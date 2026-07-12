<?php

declare(strict_types=1);

/**
 * buy-and-poll.php — Full activation lifecycle.
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_API_KEY=sk_live_xxx
 *   php examples/buy-and-poll.php
 *   # Ctrl-C at any point to cancel the active order cleanly.
 *
 * What it does
 * ------------
 *   1. Creates an activation order for COUNTRY/SERVICE.
 *   2. Polls `sms()` every 5s for up to 5 minutes.
 *   3. On SMS: prints text and calls `finish()` to commit the spend.
 *   4. On Ctrl-C OR poll timeout: calls `cancel()` to refund the hold.
 *
 * Gotchas
 * -------
 *   - `sms()` returns BOTH `stored` (delivered via webhook) and `fresh`
 *     (pulled on demand). We de-duplicate by id.
 *   - Don't poll faster than 5s — the API will 429. The SDK auto-retries
 *     once on 429 honouring Retry-After, but heavy polling burns through
 *     that allowance fast.
 *   - Always `finish()` or `cancel()`. A dangling order keeps the held
 *     balance locked until server-side expiry.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesException;

$apiKey = getenv('EVESES_API_KEY') ?: 'sk_test_placeholder';
$country = getenv('EVESES_COUNTRY') ?: 'ua';
$service = getenv('EVESES_SERVICE') ?: 'telegram';

const POLL_INTERVAL_S = 5;
const POLL_TIMEOUT_S = 5 * 60;

/**
 * De-duplicate the stored + fresh SMS lists by id, preserving order.
 *
 * @param  list<object>  $stored
 * @param  list<object>  $fresh
 * @return list<object>
 */
function dedupe_sms(array $stored, array $fresh): array
{
    $seen = [];
    $out = [];
    foreach ([...$stored, ...$fresh] as $sms) {
        $id = (int) $sms->id;
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $sms;
    }

    return $out;
}

$client = new Eveses(['api_key' => $apiKey]);
$order = null;

// Cancel cleanly on Ctrl-C. pcntl_signal is only available on POSIX hosts;
// fall back silently on Windows / when the ext isn't loaded.
$cancel_requested = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use (&$cancel_requested): void {
        $cancel_requested = true;
        fwrite(STDERR, "\nCancellation requested — releasing the number…\n");
    });
}

try {
    $order = $client->numbers->create([
        'country' => $country,
        'service' => $service,
        'idempotency_key' => bin2hex(random_bytes(16)),
    ]);
    printf("Created order %s → phone %s\n", $order->orderId, $order->phone ?? '?');
    echo "Polling for SMS (Ctrl-C to cancel the order)…\n";

    $deadline = time() + POLL_TIMEOUT_S;
    $sms = null;

    while (time() < $deadline) {
        if ($cancel_requested) {
            break;
        }
        $bundle = $client->numbers->sms($order->orderId);
        $messages = dedupe_sms($bundle->stored, $bundle->fresh);
        if ($messages !== []) {
            $sms = $messages[0];
            break;
        }
        $remaining = max(0, $deadline - time());
        printf("  ...no SMS yet, sleeping %ds (deadline in %ds)\n", POLL_INTERVAL_S, $remaining);
        sleep(POLL_INTERVAL_S);
    }

    if ($cancel_requested) {
        try {
            $client->numbers->cancel($order->orderId);
            echo "Cancelled cleanly.\n";
        } catch (EvesesException $exc) {
            if ($exc->status === 404) {
                echo "Order already in a terminal state; nothing to cancel.\n";
            } else {
                throw $exc;
            }
        }
        exit(0);
    }

    if ($sms === null) {
        echo "Timed out waiting for SMS — cancelling and refunding held balance.\n";
        $client->numbers->cancel($order->orderId);
        exit(0);
    }

    printf("Got SMS from %s: %s\n", $sms->sender ?? 'unknown', var_export($sms->text, true));
    $finished = $client->numbers->finish($order->orderId);
    printf("Order %s finished (status=%s).\n", $finished->orderId, $finished->status);

} catch (EvesesException $exc) {
    fprintf(STDERR, "SDK error (%d): %s\n", $exc->status, $exc->getMessage());
    exit(1);
}
