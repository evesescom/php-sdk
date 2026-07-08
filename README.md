# eveses/sdk (PHP SDK)

Official PHP SDK for the [Eveses](https://eveses.com) developer API.
Activations, wallet, catalog (countries / services / pricing), proxies,
web unblocker, emails, and webhook signature verification.

Zero runtime dependencies — uses PHP's built-in `ext-curl` and `ext-json`.
PHP 8.1+ with strict types throughout.

## Install

```bash
composer require eveses/sdk
```

Requirements:

- PHP 8.1+
- `ext-curl`, `ext-json`

## Quickstart

```php
<?php
require 'vendor/autoload.php';

use Eveses\Sdk\Eveses;

$client = new Eveses(['api_key' => getenv('EVESES_API_KEY')]);

$order  = $client->activations->create(['country' => 'ua', 'service' => 'telegram']);
$sms    = $client->activations->sms($order->orderId);
$wallet = $client->wallet->balance();
echo $order->orderId, ' / ', $wallet->availableBalance, ' ', $wallet->currency, PHP_EOL;
```

## Authentication

Every request sends `Authorization: Bearer <api_key>`. Generate an API key
from your dashboard (`Settings → API keys`). The token is a Sanctum
personal-access token with `kind=api_key`.

Recommended: load the key from an environment variable, never check it in.

## Activations

```php
$order = $client->activations->create([
    'country'           => 'ua',
    'service'           => 'telegram',
    'mode'              => 'activation',         // or 'rent'
    'duration_minutes'  => 60,                    // rent only
    'max_price_cents'   => 100,                   // optional ceiling
    'idempotency_key'   => 'my-uuid',             // also sent as Idempotency-Key header
]);

$fresh = $client->activations->get($order->orderId);
$sms   = $client->activations->sms($order->orderId);
//   $sms->stored — delivered to us via upstream webhook
//   $sms->fresh  — pulled from upstream provider on demand

$client->activations->cancel($order->orderId);   // refund-where-supported
$client->activations->finish($order->orderId);   // mark consumed
```

## Wallet

```php
$wallet = $client->wallet->balance();
// $wallet->balance / heldBalance / availableBalance — integers in cents
// $wallet->currency — ISO-4217, e.g. "USD"
```

## Catalog (countries / services / pricing)

Read-only metadata for driving order-creation UX. All three calls hit the
API-key-authenticated `/api/v1/numbers/*` routes, so the same Bearer token
that creates orders can populate selectors and price tables.

```php
$countries = $client->catalog->countries(['mode' => 'activation'])->countries;
$services  = $client->catalog->services(['mode' => 'activation', 'country' => 'ua'])->services;
$pricing   = $client->catalog->pricing([
    'mode'    => 'activation',
    'country' => 'ua',
    'service' => 'telegram',
]);
// $pricing->services[0]->durations[0]->priceCents → 50
```

`mode` accepts `'activation'` or `'rent'`. For rentals, pass
`'duration_minutes' => N` to `pricing()` to filter to a single duration.

## Proxies

Buy and manage residential (metered, per-GB) and static (per-IP) proxies.
Money is integer cents; the provider stays invisible behind the white-label
host. Purchases accept an optional `idempotency_key` (sent as the
`Idempotency-Key` header) so retries never double-charge.

```php
// Overview: residential connection + subscription + orders.
$px = $client->proxies->list();
// $px->residential->trafficGbAvailable, $px->subscription?->status, $px->orders

// Residential GB ladder + static (per-IP) catalogue.
$packages = $client->proxies->packages()->packages;
$catalog  = $client->proxies->catalog()->products; // plan price_cents may be null → use quote()

// Quote before buying — residential (metered) or a static selection.
$q = $client->proxies->quote(['type' => 'residential', 'gb' => 5, 'subscription' => true]);
$q = $client->proxies->quote(['type' => 'isp', 'product_id' => 1, 'plan_id' => 2, 'location_id' => 3, 'quantity' => 1]);
// $q->data — the raw quote map (price_cents, currency, discount_pct, …)

// Buy residential GB…
$order = $client->proxies->purchase([
    'type' => 'residential', 'gb' => 5, 'subscription' => false,
    'idempotency_key' => 'my-uuid',
]);
// …or static IPs.
$order = $client->proxies->purchase([
    'type' => 'isp', 'product_id' => 1, 'plan_id' => 2, 'location_id' => 3,
    'quantity' => 1, 'idempotency_key' => 'my-uuid',
]);

// Residential subscription management.
$client->proxies->cancelSubscription();
$client->proxies->pauseSubscription();
$client->proxies->resumeSubscription();
$client->proxies->resetSessions(); // rotate residential sticky-session IPs

// Static (per-IP) order management.
$client->proxies->extend($order->uuid, 30);       // re-charge for another period
$client->proxies->autoRenew($order->uuid, true);  // toggle auto-extend

// Targeting + usage.
$geo   = $client->proxies->locations('residential')->geo;
$usage = $client->proxies->usage(['from' => '2026-06-01', 'to' => '2026-06-30']);

// Connection endpoints (regions / ports / protocols).
$ep = $client->proxies->endpoints();
// $ep->regions[0]->code/host/label, $ep->ports['http'], $ep->protocols
```

## Web Unblocker

An anti-bot scraping endpoint billed per successful request. Separate product
from proxies.

```php
$wu = $client->webUnblocker->list();
// $wu->access->requestsRemaining, $wu->subscription?->status, $wu->orders

$packages = $client->webUnblocker->packages()->packages;
$quote    = $client->webUnblocker->quote(10000);          // ->priceCents, ->per1kCents
$quote    = $client->webUnblocker->quote(10000, true);    // subscription pricing

$order = $client->webUnblocker->purchase([
    'requests' => 10000, 'subscription' => false,
    'idempotency_key' => 'my-uuid',
]);

$client->webUnblocker->cancelSubscription();
$client->webUnblocker->pauseSubscription();
$client->webUnblocker->resumeSubscription();
```

## Emails

Rent an inbox address (our own catch-all domains, or a reseller) and read its
mail.

```php
$mine = $client->emails->list()->emails;
$all  = $client->emails->list(true)->emails;   // include released addresses

// Reseller providers need a `site`; our catch-all domains ignore it.
$domains = $client->emails->domains(['site' => 'example.com'])->domains;
$quote   = $client->emails->quote(['domain' => 'inbox.eveses.com', 'site' => 'example.com']);

$order = $client->emails->purchase([
    'domain' => 'inbox.eveses.com',
    'site' => 'example.com',      // reseller only
    'provider' => 'herosms',      // optional
    'idempotency_key' => 'my-uuid',
]);

// get() also live-syncs reseller inboxes — poll it for new mail.
$inbox = $client->emails->get($order->uuid);
foreach ($inbox->messages ?? [] as $m) {
    echo $m->from, ' — ', $m->subject, PHP_EOL;
}

// Paginated message list (full rows incl. id + read state).
$page = $client->emails->messages($order->uuid, 1, 20);
foreach ($page->messages as $m) {
    echo $m->id, ' ', $m->isRead ? 'read' : 'unread', ' — ', $m->subject, PHP_EOL;
}
// $page->page, $page->perPage, $page->total, $page->hasMore

// Mark one message read.
$client->emails->markRead($order->uuid, $page->messages[0]->id);

$client->emails->delete($order->uuid);   // soft cancel, no refund
```

## Webhook verification

Eveses signs every outbound webhook delivery with HMAC-SHA256 over
`"{$timestamp}.{$body}"`. Two headers carry the proof:

- `X-Eveses-Signature` — e.g. `sha256=abc123…`
- `X-Eveses-Timestamp` — unix seconds

Pass the **raw** request body (`file_get_contents('php://input')` or your
framework's "raw body" accessor) — not the parsed JSON. Re-encoding through
`json_decode` + `json_encode` reorders keys and breaks the signature.

```php
use Eveses\Sdk\Modules\Webhooks;

$rawBody = file_get_contents('php://input');
$ok = Webhooks::verify(
    rawBody:          $rawBody,
    signatureHeader:  $_SERVER['HTTP_X_EVESES_SIGNATURE'] ?? null,
    timestampHeader:  $_SERVER['HTTP_X_EVESES_TIMESTAMP'] ?? null,
    secret:           getenv('EVESES_WEBHOOK_SECRET'),
    toleranceSeconds: 300,
);
if (! $ok) {
    http_response_code(401);
    exit('bad signature');
}
$payload = json_decode($rawBody, true);
// handle $payload['event'] / $payload['data'] …
```

A convenience static method is also exposed on the main client class:

```php
Eveses\Sdk\Eveses::verifyWebhook($rawBody, $sigHeader, $tsHeader, $secret);
```

## Errors

All non-2xx responses throw an `Eveses\Sdk\Exceptions\EvesesException`
subclass:

| Status      | Class                                                              |
| ----------- | ------------------------------------------------------------------ |
| 401 / 403   | `EvesesAuthException`                                              |
| 429         | `EvesesRateLimitException` (after the 1 auto-retry is exhausted)   |
| anything else (400, 404, 422, 5xx, …) | `EvesesException` (status preserved)     |

```php
use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;

try {
    $client->wallet->balance();
} catch (EvesesAuthException $e) {
    error_log('bad api key: ' . $e->getMessage());
} catch (EvesesException $e) {
    error_log("eveses error: status={$e->status} message={$e->getMessage()}");
}
```

The base class extends `RuntimeException`, so a generic `catch (\Throwable $e)`
will still capture every SDK-thrown error.

## API surface vs OpenAPI

The Eveses public OpenAPI spec exposes the customer-facing endpoints under
`/api/account/*` (legacy account scope) and `/api/v1/numbers/*` (new
versioned public API). For API-key consumers, the v1 surface is currently
a thin wrapper around the same controllers — orders and wallet are still
served from `/api/account/*`. This SDK targets:

| OpenAPI route                                | SDK call                               |
| -------------------------------------------- | -------------------------------------- |
| `POST   /api/account/orders`                 | `$client->activations->create([...])`  |
| `GET    /api/account/orders/{uuid}`          | `$client->activations->get($id)`       |
| `GET    /api/account/orders/{uuid}/sms`      | `$client->activations->sms($id)`       |
| `POST   /api/account/orders/{uuid}/cancel`   | `$client->activations->cancel($id)`    |
| `POST   /api/account/orders/{uuid}/finish`   | `$client->activations->finish($id)`    |
| `GET    /api/account/wallet`                 | `$client->wallet->balance()`           |
| `GET    /api/v1/numbers/countries`           | `$client->catalog->countries([...])`   |
| `GET    /api/v1/numbers/products`            | `$client->catalog->services([...])`    |
| `GET    /api/v1/numbers/pricing`             | `$client->catalog->pricing([...])`     |
| `GET    /api/account/proxies`                | `$client->proxies->list()`             |
| `GET    /api/account/proxies/packages`       | `$client->proxies->packages()`         |
| `GET    /api/account/proxies/catalog`        | `$client->proxies->catalog()`          |
| `GET    /api/account/proxies/quote`          | `$client->proxies->quote([...])`       |
| `GET    /api/account/proxies/locations`      | `$client->proxies->locations($type)`   |
| `GET    /api/account/proxies/usage`          | `$client->proxies->usage([...])`       |
| `GET    /api/account/proxies/endpoints`      | `$client->proxies->endpoints()`        |
| `POST   /api/account/proxies/purchase`       | `$client->proxies->purchase([...])`    |
| `POST   /api/account/proxies/subscription/*` | `$client->proxies->{cancel,pause,resume}Subscription()` |
| `POST   /api/account/proxies/sessions/reset` | `$client->proxies->resetSessions()`    |
| `POST   /api/account/proxies/{uuid}/extend`  | `$client->proxies->extend($uuid, $days)` |
| `POST   /api/account/proxies/{uuid}/auto-renew` | `$client->proxies->autoRenew($uuid, $bool)` |
| `GET    /api/account/web-unblocker`          | `$client->webUnblocker->list()`        |
| `GET    /api/account/web-unblocker/packages` | `$client->webUnblocker->packages()`    |
| `GET    /api/account/web-unblocker/quote`    | `$client->webUnblocker->quote($n)`     |
| `POST   /api/account/web-unblocker/purchase` | `$client->webUnblocker->purchase([...])` |
| `POST   /api/account/web-unblocker/subscription/*` | `$client->webUnblocker->{cancel,pause,resume}Subscription()` |
| `GET    /api/account/emails`                 | `$client->emails->list()`              |
| `GET    /api/account/emails/domains`         | `$client->emails->domains([...])`      |
| `GET    /api/account/emails/quote`           | `$client->emails->quote([...])`        |
| `POST   /api/account/emails/purchase`        | `$client->emails->purchase([...])`     |
| `GET    /api/account/emails/{uuid}`          | `$client->emails->get($uuid)`          |
| `GET    /api/account/emails/{uuid}/messages` | `$client->emails->messages($uuid, $page, $perPage)` |
| `POST   /api/account/emails/{uuid}/messages/{id}/read` | `$client->emails->markRead($uuid, $id)` |
| `DELETE /api/account/emails/{uuid}`          | `$client->emails->delete($uuid)`       |
| _(webhook deliveries)_                       | `Webhooks::verify(...)`                |

## Configuration

```php
$client = new Eveses([
    'api_key'         => '…',
    'base_url'        => 'https://api.eveses.com',  // override per environment
    'timeout'         => 30,                        // seconds
    'user_agent'      => 'my-app/1.2.3',
    'default_headers' => ['X-Trace-Id' => 't1'],
    // 'transport' => fn(array $req) => [...],     // test-only HTTP hook
]);
```

## Development

```bash
composer install
vendor/bin/phpunit
```

The test suite uses a tiny callable transport hook for HTTP — no Guzzle,
no Mockery, no networking. See `tests/EvesesTest.php`.

## License

MIT
