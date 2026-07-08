<?php

declare(strict_types=1);

/**
 * proxies-unblocker-emails.php — the three product modules end-to-end.
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_API_KEY=sk_live_xxx
 *   php examples/proxies-unblocker-emails.php
 *
 * What it does
 * ------------
 *   1. Proxies      — quote + buy 5 GB of residential traffic, then extend a
 *                     static order and toggle auto-renew.
 *   2. Web Unblocker— quote + buy a 10k-request bundle.
 *   3. Emails       — rent an inbox address and poll it once for mail.
 *
 * Idempotency note
 * ----------------
 * Every purchase() sends an `idempotency_key` (also set as the
 * `Idempotency-Key` header), so a retried purchase returns the SAME order
 * instead of double-charging. Generate the key once per user intent.
 *
 * This is a READ-heavy illustration; the purchases below WILL spend wallet
 * funds if the key is real. Comment them out to just browse catalogues.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesException;

$client = new Eveses(['api_key' => getenv('EVESES_API_KEY') ?: 'sk_test_placeholder']);

$idem = static fn (): string => bin2hex(random_bytes(16));

try {
    // ── Proxies ──────────────────────────────────────────────────────────
    $overview = $client->proxies->list();
    printf(
        "Proxies: residential %s, %d order(s)\n",
        $overview->residential ? 'provisioned' : 'none',
        count($overview->orders),
    );

    $quote = $client->proxies->quote(['type' => 'residential', 'gb' => 5, 'subscription' => false]);
    printf("  5 GB residential quote: %d cents\n", (int) ($quote->data['price_cents'] ?? 0));

    $order = $client->proxies->purchase([
        'type' => 'residential',
        'gb' => 5,
        'subscription' => false,
        'idempotency_key' => $idem(),
    ]);
    printf("  bought order %s (%d cents)\n", $order->uuid, $order->priceCents);

    // Static-order management (uuid must be a per-IP order that exists).
    if ($overview->orders !== [] && $overview->orders[0]->extendable) {
        $uuid = $overview->orders[0]->uuid;
        $client->proxies->extend($uuid, 30);
        $client->proxies->autoRenew($uuid, true);
        printf("  extended + auto-renew ON for %s\n", $uuid);
    }

    // ── Web Unblocker ────────────────────────────────────────────────────
    $wuQuote = $client->webUnblocker->quote(10_000);
    printf(
        "Web Unblocker: 10k requests = %d cents (%d/1k)\n",
        $wuQuote->priceCents,
        $wuQuote->per1kCents,
    );

    $wuOrder = $client->webUnblocker->purchase([
        'requests' => 10_000,
        'subscription' => false,
        'idempotency_key' => $idem(),
    ]);
    printf("  bought %s (%d requests)\n", $wuOrder->uuid, $wuOrder->requests);

    // ── Emails ───────────────────────────────────────────────────────────
    $domains = $client->emails->domains();
    printf("Emails: %d rentable domain(s)\n", count($domains->domains));

    if ($domains->domains !== []) {
        $pick = $domains->domains[0];
        $emQuote = $client->emails->quote(['domain' => $pick->domain]);
        printf("  %s quote: %d cents\n", $pick->domain, $emQuote->priceCents);

        $address = $client->emails->purchase([
            'domain' => $pick->domain,
            'idempotency_key' => $idem(),
        ]);
        printf("  rented %s (%s)\n", $address->address, $address->uuid);

        // get() live-syncs reseller inboxes — poll it for new mail.
        $inbox = $client->emails->get($address->uuid);
        printf("  %d message(s) so far\n", count($inbox->messages ?? []));
        foreach ($inbox->messages ?? [] as $m) {
            printf("    from %s — %s\n", $m->from ?? '?', $m->subject ?? '(no subject)');
        }
    }
} catch (EvesesException $exc) {
    fprintf(STDERR, "SDK error (%d): %s\n", $exc->status, $exc->getMessage());
    exit(1);
}
