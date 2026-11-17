<?php

declare(strict_types=1);

namespace Netgsm\Sms\Http;

use RuntimeException;

interface Transport
{
    /**
     * Sends one HTTP request. Must not follow redirects.
     *
     * $headers carries the access token and $body can carry the password: mark both #[\SensitiveParameter]
     * in implementations so they stay out of stack traces.
     *
     * @param list<string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string} Header names are lower-case.
     *
     * @throws RuntimeException When no response was received (connection error, timeout).
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
