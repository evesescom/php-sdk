<?php

declare(strict_types=1);

/**
 * webhook-server.php — Minimal HTTP server that verifies Eveses webhooks.
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_WEBHOOK_SECRET=whsec_xxx   # from your endpoint settings
 *   export PORT=8787                          # optional
 *   php -S 0.0.0.0:8787 examples/webhook-server.php
 *   # Then point Eveses at  http://localhost:8787/eveses/webhook
 *   # (use ngrok / cloudflared in real life — Eveses needs a public URL.)
 *
 * What it does
 * ------------
 * - Listens on POST /eveses/webhook (via PHP's built-in dev server).
 * - Reads the raw body BEFORE any json_decode (signature is over raw
 *   bytes — json_decode + json_encode would reorder keys and invalidate
 *   the HMAC).
 * - Calls Webhooks::verify() with the X-Eveses-Signature +
 *   X-Eveses-Timestamp headers. Default tolerance is 300s — older
 *   deliveries are rejected (replay protection).
 * - Returns 200 on success, 401 on bad signature, 400 on malformed body.
 *
 * Gotchas
 * -------
 * - php-fpm / mod_php strip request bodies on some methods. The CLI
 *   built-in server (this script) doesn't, but on a real deployment make
 *   sure your stack forwards POST bodies untouched.
 * - `Webhooks::verify` returns false for ANY failure (missing header, bad
 *   hex, expired timestamp). That's not an error — it just means "not a
 *   valid Eveses delivery".
 * - Replay-protection window is 300s. Don't widen it unless your handler
 *   is idempotent and you have a very good reason.
 * - Respond within ~10s. Enqueue heavy work and ACK fast.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Modules\Webhooks;

$webhookSecret = getenv('EVESES_WEBHOOK_SECRET') ?: 'whsec_placeholder';
$expectedPath = '/eveses/webhook';

// When run via `php -S 0.0.0.0:8787 examples/webhook-server.php` PHP
// invokes this script once per incoming request and exposes
// $_SERVER + php://input as usual.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

if ($method !== 'POST' || $path !== $expectedPath) {
    json_response(404, ['error' => 'not_found']);
    exit;
}

// Raw body — DON'T json_decode then re-encode; that breaks the HMAC.
$rawBody = (string) file_get_contents('php://input');

$signature = $_SERVER['HTTP_X_EVESES_SIGNATURE'] ?? null;
$timestamp = $_SERVER['HTTP_X_EVESES_TIMESTAMP'] ?? null;

$ok = Webhooks::verify(
    rawBody: $rawBody,
    signatureHeader: is_string($signature) ? $signature : null,
    timestampHeader: is_string($timestamp) ? $timestamp : null,
    secret: $webhookSecret,
    toleranceSeconds: 300,
);

if (! $ok) {
    // Don't leak which check failed — that's a signature-forgery oracle.
    json_response(401, ['error' => 'invalid_signature']);
    exit;
}

try {
    $payload = $rawBody !== '' ? json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR) : [];
} catch (JsonException) {
    json_response(400, ['error' => 'invalid_json']);
    exit;
}

$type = is_array($payload) && isset($payload['type']) ? (string) $payload['type'] : '?';
fwrite(STDOUT, "Received verified webhook: type={$type}\n");
fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// ACK fast. Real handlers should enqueue the event and respond here.
json_response(200, ['received' => true]);

/**
 * @param  array<string,mixed>  $body
 */
function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}
