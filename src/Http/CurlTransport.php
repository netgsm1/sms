<?php

declare(strict_types=1);

namespace Netgsm\Sms\Http;

use RuntimeException;

final class CurlTransport implements Transport
{
    /**
     * @param int         $timeout        Total request time limit in seconds.
     * @param int         $connectTimeout Connection time limit in seconds.
     * @param string|null $caBundle       Path to a CA bundle, if the system default cannot be used. TLS verification is always on.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly ?string $caBundle = null,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException(curl_error($ch), curl_errno($ch));
        }

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders,
            'body' => (string) $raw,
        ];
    }
}
