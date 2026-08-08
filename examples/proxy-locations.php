<?php

declare(strict_types=1);

/**
 * proxy-locations.php — Browse residential proxy geo targeting.
 *
 * Run me
 * ------
 *   cd sdk/php
 *   composer install
 *   export EVESES_API_KEY=sk_live_xxx
 *   php examples/proxy-locations.php
 *
 * What it does
 * ------------
 *   1. Builds an authenticated client (Bearer Sanctum API-key token).
 *   2. Lists top-level residential targeting (countries / regions / sets).
 *   3. Drills into ONE country with `locationsDetail()` and prints the
 *      per-country state and city breakdown.
 *
 * Notes
 * -----
 *   - `locations()` and `locationsDetail()` return plain associative arrays.
 *   - `locationsDetail()` returns `{type, country, geo:{states, cities, …}}`;
 *     use it to build a state/city/ISP picker for residential targeting.
 */

require_once __DIR__.'/../vendor/autoload.php';

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;

$apiKey = getenv('EVESES_API_KEY') ?: 'sk_test_placeholder';
$country = getenv('EVESES_PROXY_COUNTRY') ?: 'us';

$client = new Eveses(['api_key' => $apiKey]);

try {
    // Top-level residential targeting: countries / regions / sets.
    $locations = $client->proxy->locations('residential');
    $countries = $locations['countries'] ?? $locations['data'] ?? [];
    printf(
        "Residential targeting: %d country(ies) available\n",
        is_countable($countries) ? count($countries) : 0,
    );

    // Drill into a single country for the state/city/ISP breakdown.
    $detail = $client->proxy->locationsDetail($country);
    $geo = $detail['geo'] ?? [];
    $states = $geo['states'] ?? [];
    $cities = $geo['cities'] ?? [];

    printf(
        "%s: %d state(s), %d city(ies)\n",
        strtoupper($detail['country'] ?? $country),
        is_countable($states) ? count($states) : 0,
        is_countable($cities) ? count($cities) : 0,
    );

    echo "States:\n";
    foreach (array_slice((array) $states, 0, 10) as $state) {
        printf("  - %s (%s)\n", $state['name'] ?? '?', $state['code'] ?? '?');
    }

    echo "Cities:\n";
    foreach (array_slice((array) $cities, 0, 10) as $city) {
        printf("  - %s (%s)\n", $city['name'] ?? '?', $city['code'] ?? '?');
    }

} catch (EvesesAuthException) {
    fwrite(STDERR, "Auth failed — check EVESES_API_KEY (must start with sk_).\n");
    exit(1);
} catch (EvesesException $exc) {
    fprintf(STDERR, "SDK error (%d): %s\n", $exc->status, $exc->getMessage());
    exit(1);
}
