<?php

declare(strict_types=1);

namespace Netgsm\Sms;

use RuntimeException;
use Throwable;

final class NetgsmException extends RuntimeException
{
    /**
     * @param int    $status            HTTP status; 0 when no response was received.
     * @param string $errorCode         API error code (e.g. REJECTED_HEADER, invalid_credentials) or NETWORK_ERROR / INVALID_RESPONSE.
     * @param bool   $mayHaveBeenQueued True when a send request may have created a job despite the error. Do not resend blindly; check report() first.
     * @param list<array{field?: string, message?: string}>                 $violations
     * @param list<array{index?: int, recipient?: string, reason?: string}> $rejectedItems
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $mayHaveBeenQueued = false,
        public readonly array $violations = [],
        public readonly array $rejectedItems = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Builds the exception from an SMS API or Identity API error response.
     *
     * @param array<string, mixed> $body
     */
    public static function fromResponse(int $status, array $body, ?int $retryAfter, bool $mayHaveBeenQueued): self
    {
        $errorCode = is_string($body['code'] ?? null) ? $body['code'] : 'HTTP_' . $status;
        $detail = $body['message'] ?? $body['detail'] ?? '';
        $requestId = $body['requestId'] ?? $body['request_id'] ?? null;

        $message = sprintf('Netgsm API error %d %s', $status, $errorCode);
        if (is_string($detail) && $detail !== '') {
            $message .= ': ' . $detail;
        }
        if (is_string($requestId)) {
            $message .= ' (requestId ' . $requestId . ')';
        }

        return new self(
            $message,
            $status,
            $errorCode,
            is_string($requestId) ? $requestId : null,
            $retryAfter,
            $mayHaveBeenQueued,
            is_array($body['violations'] ?? null) ? $body['violations'] : [],
            is_array($body['rejectedItems'] ?? null) ? $body['rejectedItems'] : [],
        );
    }
}
