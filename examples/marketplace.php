<?php

declare(strict_types=1);

/**
 * marketplace.php — Browse the marketplace catalog (and buy, commented out).
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_API_KEY=sk_live_xxx
 *   php examples/marketplace.php
 *
 * What it does
 * ------------
 *   1. Builds an authenticated client (Bearer Sanctum API-key token).
 *   2. Lists the filter facets for the `accounts` category.
 *   3. Lists the marketplace categories.
 *   4. Browses the catalog for autoreg US accounts, grouped by attributes,
 *      and prints a few groups with their `prices_cents` variants.
 *
 * Notes
 * -----
 *   - Discovery (`catalog`, `categories`, `filters`) is PUBLIC and returns
 *     plain associative arrays, not typed objects.
 *   - `group_by = attributes` collapses same-type products into groups; each
 *     group exposes a `prices_cents` list of the available price variants.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;

$apiKey = getenv('EVESES_API_KEY') ?: 'sk_test_placeholder';
$category = getenv('EVESES_MARKETPLACE_CATEGORY') ?: 'accounts';

$client = new Eveses(['api_key' => $apiKey]);

try {
    // Filter facets tell you which attribute values are buyable for a category.
    $filters = $client->marketplace->filters($category);
    printf("Filter facets for '%s': %s\n", $category, implode(', ', array_keys($filters)));

    // The category list drives your top-level marketplace navigation.
    $categories = $client->marketplace->categories();
    $categoryList = $categories['categories'] ?? $categories['data'] ?? $categories;
    printf("%d marketplace categories\n", is_countable($categoryList) ? count($categoryList) : 0);

    // Browse the catalog. Only the provided filters are sent as query params.
    $catalog = $client->marketplace->catalog([
        'category' => $category,
        'country' => 'US',
        'origin' => 'autoreg',
        'group_by' => 'attributes',
    ]);

    // With group_by=attributes the payload carries a `groups` list; each group
    // has normalized attributes plus a `prices_cents` variant list.
    $groups = $catalog['groups'] ?? $catalog['data'] ?? [];
    printf("Catalog returned %d group(s):\n", is_countable($groups) ? count($groups) : 0);

    foreach (array_slice((array) $groups, 0, 5) as $group) {
        $attrs = $group['attributes'] ?? [];
        $label = implode('/', array_filter([
            $attrs['country'] ?? null,
            $attrs['origin'] ?? null,
            $attrs['format'] ?? null,
            isset($attrs['twofa']) ? ($attrs['twofa'] ? '2fa' : 'no-2fa') : null,
        ]));
        $prices = $group['prices_cents'] ?? [];
        printf(
            "  - %s: %s (prices_cents: %s)\n",
            $group['sku'] ?? '?',
            $label !== '' ? $label : '(no attributes)',
            $prices === [] ? '—' : implode(', ', array_map('strval', (array) $prices)),
        );
    }

    // ---------------------------------------------------------------------
    // Buy + reveal (commented out — uncomment to spend real balance):
    //
    //   $quote = $client->marketplace->quote($category, 'the-sku');
    //   printf("Quote: %d cents\n", $quote['price_cents'] ?? 0);
    //
    //   $order = $client->marketplace->buy(
    //       category:       $category,
    //       sku:            'the-sku',
    //       quantity:       1,
    //       idempotencyKey: bin2hex(random_bytes(16)),
    //   );
    //   $uuid = $order['uuid'] ?? $order['order']['uuid'];
    //
    //   $revealed = $client->marketplace->reveal($uuid);
    //   // $revealed['items'] — the delivered goods (credentials / tdata / …)
    // ---------------------------------------------------------------------

} catch (EvesesAuthException) {
    fwrite(STDERR, "Auth failed — check EVESES_API_KEY (must start with sk_).\n");
    exit(1);
} catch (EvesesException $exc) {
    fprintf(STDERR, "SDK error (%d): %s\n", $exc->status, $exc->getMessage());
    exit(1);
}
