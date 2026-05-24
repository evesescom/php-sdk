<?php

declare(strict_types=1);

namespace Eveses\Sdk\Exceptions;

/**
 * Raised on 401 / 403. The PHP SDK collapses both auth-related statuses
 * onto this single class to match the high-level surface used by callers
 * who only care "did my key get rejected?".
 *
 * For 403 specifically, ``$status`` is preserved so callers that want to
 * differentiate "unauthenticated" vs "forbidden" still can.
 */
final class EvesesAuthException extends EvesesException
{
    public function __construct(
        string $message = 'Unauthenticated',
        int $status = 401,
        mixed $body = null,
    ) {
        parent::__construct(
            message: $message !== '' ? $message : 'Unauthenticated',
            status: $status,
            errorCode: $status === 403 ? 'forbidden' : 'unauthenticated',
            body: $body,
        );
    }
}
