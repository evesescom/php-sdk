<?php

declare(strict_types=1);

/**
 * quickstart.php — Hello-world for the Eveses PHP SDK.
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_API_KEY=sk_live_xxx
 *   php examples/quickstart.php
 *
 * What it does
 * ------------
 *   1. Builds an authenticated client (Bearer Sanctum API-key token).
 *   2. Reads the wallet balance (so you can see currency + available funds).
 *   3. Lists service codes for one country.
 *   4. Buys ONE activation, passing an idempotency key.
 *
 * Idempotency note
 * ----------------
 * We send a random `idempotency_key` so this script is safe to retry on
 * network blips: the API returns the SAME order on a retry rather than
 * charging you twice for two numbers. In production, generate the key
 * once per *user intent* (when the user clicks Buy), not per HTTP attempt.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;

$apiKey = getenv('EVESES_API_KEY') ?: 'sk_test_placeholder';
$country = getenv('EVESES_COUNTRY') ?: 'ua';
$service = getenv('EVESES_SERVICE') ?: 'telegram';

// The constructor only validates that the key is non-empty; the first
// real request is where 401s surface. We catch the whole EvesesException
// family at the boundary.
$client = new Eveses(['api_key' => $apiKey]);

try {
    // Wallet balance is reported in MINOR units (cents). Mind the split:
    //   availableBalance — spendable right now
    //   heldBalance      — reserved against in-flight orders
    //   balance          — availableBalance + heldBalance
    $wallet = $client->wallet->balance();
    printf(
        "Wallet: %.2f %s available (held: %.2f)\n",
        $wallet->availableBalance / 100,
        $wallet->currency,
        $wallet->heldBalance / 100,
    );

    // `services()` is the global product catalog for the mode; `country`
    // is informational on v1 today.
    $services = $client->numbers->services(['mode' => 'activation', 'country' => $country]);
    printf("%d services available (mode=%s)\n", count($services->services), $services->mode);
    if (! in_array($service, $services->services, true)) {
        fwrite(STDERR, "Warning: '{$service}' not in catalog — request may 404.\n");
    }

    // The idempotency key MUST be stable across retries of the same intent.
    // random_bytes() + bin2hex() is plenty for this script which only calls
    // create() once.
    $idempotencyKey = bin2hex(random_bytes(16));

    $order = $client->numbers->create([
        'country' => $country,
        'service' => $service,
        'mode' => 'activation',
        'idempotency_key' => $idempotencyKey,
    ]);
    printf(
        "Created order %s: phone=%s status=%s\n",
        $order->orderId,
        $order->phone ?? '?',
        $order->status,
    );
    echo "Next: poll \$client->numbers->sms(\$order->orderId) for the code.\n";

} catch (EvesesAuthException) {
    fwrite(STDERR, "Auth failed — check EVESES_API_KEY (must start with sk_).\n");
    exit(1);
} catch (EvesesException $exc) {
    // Validation errors (400/422) and everything else land here. The base
    // class exposes ->status, ->errorCode, ->body.
    fprintf(STDERR, "SDK error (%d): %s\n", $exc->status, $exc->getMessage());
    if (is_array($exc->body) && isset($exc->body['errors']) && is_array($exc->body['errors'])) {
        foreach ($exc->body['errors'] as $field => $msgs) {
            fprintf(STDERR, "  %s: %s\n", $field, implode(', ', (array) $msgs));
        }
    }
    exit(1);
}
