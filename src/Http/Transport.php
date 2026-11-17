<?php

declare(strict_types=1);

namespace Netgsm\Sms\Http;

use RuntimeException;

interface Transport
{
    /**
     * Sends one HTTP request. Must not follow redirects.
     *
     * @param list<string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string} Header names are lower-case.
     *
     * @throws RuntimeException When no response was received (connection error, timeout).
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
