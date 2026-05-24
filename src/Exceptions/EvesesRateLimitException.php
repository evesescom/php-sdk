<?php

declare(strict_types=1);

namespace Eveses\Sdk\Exceptions;

/**
 * Raised when the API responds with HTTP 429 *after* the SDK's one
 * automatic retry has been exhausted. ``$retryAfter`` carries the
 * Retry-After hint (in seconds, capped to 60) that the server returned
 * with the second 429 — useful for callers implementing their own
 * back-off on top of the SDK.
 */
final class EvesesRateLimitException extends EvesesException
{
    public function __construct(
        string $message = 'Rate limited',
        public readonly ?int $retryAfter = null,
        mixed $body = null,
    ) {
        parent::__construct(
            message: $message !== '' ? $message : 'Rate limited',
            status: 429,
            errorCode: 'rate_limited',
            body: $body,
        );
    }
}
