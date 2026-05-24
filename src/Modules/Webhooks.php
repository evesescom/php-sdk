<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

/**
 * Webhook signature verification.
 *
 * Eveses signs every webhook delivery with HMAC-SHA256 over
 * ``"{$timestamp}.{$body}"``, using the endpoint's signing secret. Two
 * headers carry the proof:
 *
 *   X-Eveses-Signature  -> "sha256=<hex>"
 *   X-Eveses-Timestamp  -> unix seconds (string)
 *
 * Always pass the **raw** request body (the exact bytes from
 * ``file_get_contents('php://input')`` or your framework's "raw body"
 * accessor). Re-encoding through ``json_decode`` + ``json_encode``
 * reorders keys and breaks the signature.
 */
final class Webhooks
{
    /**
     * Verify an Eveses webhook signature.
     *
     * @param  string  $rawBody  Raw HTTP body (NOT the parsed JSON).
     * @param  string|null  $signatureHeader  ``X-Eveses-Signature`` value, e.g. "sha256=abc123…".
     * @param  string|null  $timestampHeader  ``X-Eveses-Timestamp`` value (unix seconds).
     * @param  string  $secret  Endpoint signing secret.
     * @param  int  $toleranceSeconds  Reject timestamps drifting more than this from now.
     *                                 Pass ``0`` to disable the staleness check.
     * @return bool ``true`` iff the signature is valid and within tolerance.
     */
    public static function verify(
        string $rawBody,
        ?string $signatureHeader,
        ?string $timestampHeader,
        string $secret,
        int $toleranceSeconds = 300,
    ): bool {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $expectedHex = self::stripPrefix($signatureHeader);
        if ($expectedHex === '' || preg_match('/^[a-f0-9]+$/i', $expectedHex) !== 1) {
            return false;
        }

        if ($timestampHeader === null || $timestampHeader === '') {
            return false;
        }
        $ts = filter_var($timestampHeader, FILTER_VALIDATE_INT);
        if ($ts === false || $ts <= 0) {
            return false;
        }

        if ($toleranceSeconds > 0) {
            $now = time();
            if (abs($now - $ts) > $toleranceSeconds) {
                return false;
            }
        }

        $computed = hash_hmac('sha256', $ts.'.'.$rawBody, $secret);

        return hash_equals(strtolower($computed), strtolower($expectedHex));
    }

    private static function stripPrefix(string $value): string
    {
        $trimmed = trim($value);

        return str_starts_with($trimmed, 'sha256=') ? substr($trimmed, 7) : $trimmed;
    }
}
