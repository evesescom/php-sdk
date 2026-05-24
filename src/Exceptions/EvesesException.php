<?php

declare(strict_types=1);

namespace Eveses\Sdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for all SDK errors.
 *
 * Mirrors the JS / Python SDKs: every non-2xx response is converted into an
 * EvesesException subclass:
 *   400/422 -> EvesesValidationException
 *   401     -> EvesesAuthException
 *   403     -> EvesesForbiddenException
 *   404     -> EvesesNotFoundException
 *   429     -> EvesesRateLimitException (after the 1 auto-retry is exhausted)
 *   5xx     -> EvesesServerException
 *   other   -> EvesesException
 */
class EvesesException extends RuntimeException
{
    public readonly int $status;

    public readonly ?string $errorCode;

    public readonly mixed $body;

    public function __construct(
        string $message,
        int $status = 0,
        ?string $errorCode = null,
        mixed $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
    }
}
