<?php

declare(strict_types=1);

namespace Netgsm\Sms\Tests;

use Netgsm\Sms\Http\Transport;
use RuntimeException;

final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $requests = [];

    /** @var list<array{status: int, headers: array<string, string>, body: string}|RuntimeException> */
    private array $responses = [];

    public function queue(int $status, array $body = [], array $headers = []): self
    {
        return $this->queueRaw($status, json_encode($body), $headers);
    }

    public function queueRaw(int $status, string $body, array $headers = []): self
    {
        $this->responses[] = ['status' => $status, 'headers' => $headers, 'body' => $body];

        return $this;
    }

    public function queueToken(string $token = 'token-1', int $expiresIn = 86400): self
    {
        return $this->queue(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'refresh_token' => 'r']);
    }

    public function queueFailure(string $message = 'Connection timed out'): self
    {
        $this->responses[] = new RuntimeException($message);

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException("Unexpected request: $method $url");
        }
        if ($response instanceof RuntimeException) {
            throw $response;
        }

        return $response;
    }
}
