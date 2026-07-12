# eveses/sdk (PHP SDK)

Official PHP SDK for the [Eveses](https://eveses.com) developer API.
Numbers (SMS orders + catalog), wallet, proxies, web-unblocker, temporary
emails, captcha-solving, free trials, cross-product order history,
aggregate pricing / quotas, account (`me`), and webhook signature
verification. Every authenticated call targets the `/api/v1/*` surface.

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

$order  = $client->numbers->create(['country' => 'ua', 'service' => 'telegram']);
$sms    = $client->numbers->sms($order->orderId);
$wallet = $client->wallet->balance();
echo $order->orderId, ' / ', $wallet->availableBalance, ' ', $wallet->currency, PHP_EOL;
```

## Authentication

Every request sends `Authorization: Bearer <api_key>`. Generate an API key
from your dashboard (`Settings → API keys`). The token is a Sanctum
personal-access token with `kind=api_key`.

Recommended: load the key from an environment variable, never check it in.

## Numbers (SMS orders + catalog)

One consolidated module for the whole SMS surface — order lifecycle **and**
the read-only catalog that drives order-creation UX. All calls hit
`/api/v1/numbers/*`.

```php
// Order lifecycle
$order = $client->numbers->create([
    'country'           => 'ua',
    'service'           => 'telegram',
    'mode'              => 'activation',         // or 'rent'
    'duration_minutes'  => 60,                    // rent only
    'max_price_cents'   => 100,                   // optional ceiling
    'idempotency_key'   => 'my-uuid',             // also sent as Idempotency-Key header
]);

$fresh = $client->numbers->get($order->orderId);
$sms   = $client->numbers->sms($order->orderId);
//   $sms->stored — delivered to us via upstream webhook
//   $sms->fresh  — pulled from upstream provider on demand

$client->numbers->cancel($order->orderId);       // refund-where-supported
$client->numbers->finish($order->orderId);       // mark consumed
$client->numbers->retry($order->orderId);        // ask for another code on the same number
$client->numbers->repeat($order->orderId);       // fresh order for the same target
$client->numbers->autoRenew($order->orderId, true); // rentals: toggle auto-renew

$client->numbers->batch([                         // create several at once
    ['country' => 'ua', 'service' => 'telegram'],
    ['country' => 'pl', 'service' => 'whatsapp'],
], ['idempotency_key' => 'batch-uuid']);

$list    = $client->numbers->list(['status' => 'active']);   // GET /api/v1/numbers/orders
$summary = $client->numbers->summary();                       // GET /api/v1/numbers/orders/summary

// Catalog
$countries = $client->numbers->countries(['mode' => 'activation'])->countries;
$services  = $client->numbers->services(['mode' => 'activation', 'country' => 'ua'])->services;
$carriers  = $client->numbers->carriers(['mode' => 'activation', 'country' => 'us']);
$states    = $client->numbers->states(['mode' => 'activation', 'country' => 'us']);
$pricing   = $client->numbers->pricing([
    'mode'    => 'activation',
    'country' => 'ua',
    'service' => 'telegram',
]);
// $pricing->services[0]->durations[0]->priceCents → 50
```

`mode` accepts `'activation'` or `'rent'`. For rentals, pass
`'duration_minutes' => N` to `pricing()` to filter to a single duration.

## Wallet

```php
$wallet = $client->wallet->balance();
// $wallet->balance / heldBalance / availableBalance — integers in cents
// $wallet->currency — ISO-4217, e.g. "USD"
```

## Account (`me`)

```php
$me = $client->account->me();
// $me->abilities — what THIS token can do, e.g. ["*"]
// $me->features  — product flags: $me->features->proxy, ->captcha, …
// $me->raw       — the full me payload
```

Use `$me->features` to gate product entry points instead of hardcoding flags.

## Proxies

Buy and manage residential (metered, per-GB) and static (per-IP: ISP /
datacenter / IPv6 / sneaker / mobile) proxies. The upstream provider stays
invisible — connection details come back under the white-label host.

```php
// Browse
$pricing   = $client->proxy->pricing();                     // residential GB ladder + static catalogue
$locations = $client->proxy->locations('residential');      // targeting for a type

// Estimate then buy
$quote = $client->proxy->quote(['type' => 'residential', 'gb' => 5]);
$order = $client->proxy->purchase([                          // POST /api/v1/proxy/orders
    'type'            => 'residential',
    'gb'              => 5,
    'subscription'    => false,
    'idempotency_key' => 'my-uuid',
]);
// static (per-IP) purchase:
$order = $client->proxy->purchase([
    'type'        => 'isp',
    'product_id'  => 1,
    'plan_id'     => 2,
    'location_id' => 3,
    'quantity'    => 1,
]);

// Manage
$mine = $client->proxy->list();                             // GET /api/v1/proxy/orders
$one  = $client->proxy->get($order->uuid);                 // GET /api/v1/proxy/orders/{uuid}
$client->proxy->extend($order->uuid, 30);                  // re-charge a per-IP order
$client->proxy->autoRenew($order->uuid, true);             // toggle auto_extend
$client->proxy->resetSessions();                           // rotate residential sticky IPs
$client->proxy->usage(['from' => '2026-06-01', 'to' => '2026-06-30']);
$client->proxy->trial();                                    // free proxy trial (one-time)

// Residential subscription lifecycle
$client->proxy->subscriptionPause();
$client->proxy->subscriptionResume();
$client->proxy->subscriptionCancel();
```

## Web Unblocker

Request-based web-unblocker access, subscriptions, and a free trial.

```php
$pricing  = $client->webUnblocker->pricing();
$quote    = $client->webUnblocker->quote(1000, subscription: false);

$access = $client->webUnblocker->purchase(1000, subscription: false, idempotencyKey: 'my-uuid');
// $access->requests / requestsUsed / status / priceCents / currency

$client->webUnblocker->trial();                             // free trial (one-time)
$current = $client->webUnblocker->access();                 // budget + credentials + subscription

$client->webUnblocker->subscriptionPause();
$client->webUnblocker->subscriptionResume();
$client->webUnblocker->subscriptionCancel();
```

## Emails

Buy and manage temporary / disposable email inboxes, then poll them for
messages.

```php
$pricing = $client->emails->pricing();                      // domains under the `domains` key; ->pricing('site.com')
$quote   = $client->emails->quote('example.com');

$mailbox = $client->emails->purchase('example.com', idempotencyKey: 'my-uuid');
// $mailbox->uuid / address / status / priceCents / currency

$all      = $client->emails->list();                        // ->list(includeReleased: true) to include freed
$one      = $client->emails->get($mailbox->uuid);
$messages = $client->emails->messages($mailbox->uuid, page: 1, perPage: 20);
$client->emails->markRead($mailbox->uuid, $messageId);
$client->emails->release($mailbox->uuid);                   // delete/free the address
```

## Captcha solving

Resells 2captcha, billed pay-per-use from the wallet (count-on-success).
`solve()` blocks: it submits the task, then polls honouring the API's
`retry_after` until the task is `ready`/`failed` or the timeout elapses.

```php
$result = $client->captcha->solve('recaptcha_v2', [
    'sitekey' => '6Le-...',
    'url'     => 'https://example.com/login',
], [
    'timeout_sec'     => 180,
    'idempotency_key' => 'my-uuid',
    // 'callback_url' => 'https://my.app/captcha-webhook',
]);
// $result->taskId / status / solution / priceMicroUsd
```

A `failed` task or a timeout throws `Eveses\Sdk\Exceptions\EvesesException`.

```php
$rates = $client->captcha->rates();                         // per-solve retail rates by type
$usage = $client->captcha->usage(['status' => 'ready']);    // cursor-paginated task history
```

## Orders, pricing & quotas (aggregate)

Cross-product views for unified dashboards.

```php
// Global order history (normalized OrderView; captcha excluded → see usage())
$feed = $client->orders->list(['service' => 'proxy', 'limit' => 50]);
$one  = $client->orders->get($uuid);                        // GET /api/v1/orders/{uuid}

// All prices in one call
$prices = $client->pricing->all();                          // GET /api/v1/pricing

// Remaining prepaid balances (only products with a decrementing counter)
$quotas = $client->quotas->all();                           // GET /api/v1/quotas
```

## Trials

Check and activate free-trial access for one or more product services.

```php
$status = $client->trial->status();                         // eligibility / active services
$client->trial->subscribe(['proxies', 'web-unblocker']);    // enrol service slugs
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

Every authenticated call targets the consolidated `/api/v1/*` surface. This
SDK maps to it as follows:

| v1 route                                     | SDK call                               |
| -------------------------------------------- | -------------------------------------- |
| `POST   /api/v1/numbers/orders`              | `$client->numbers->create([...])`      |
| `GET    /api/v1/numbers/orders/{uuid}`       | `$client->numbers->get($id)`           |
| `GET    /api/v1/numbers/orders/{uuid}/sms`   | `$client->numbers->sms($id)`           |
| `POST   /api/v1/numbers/orders/{uuid}/{cancel,finish,retry,repeat,auto-renew}` | `$client->numbers->{cancel,finish,retry,repeat,autoRenew}($id)` |
| `POST   /api/v1/numbers/orders/batch`        | `$client->numbers->batch([...])`       |
| `GET    /api/v1/numbers/orders(/summary)`    | `$client->numbers->list()` / `summary()` |
| `GET    /api/v1/numbers/{countries,products,carriers,states,pricing}` | `$client->numbers->{countries,services,carriers,states,pricing}([...])` |
| `GET    /api/v1/wallet`                       | `$client->wallet->balance()`           |
| `GET    /api/v1/me`                           | `$client->account->me()`               |
| `/api/v1/proxy/*` (buy `POST /orders`)        | `$client->proxy->…`                    |
| `/api/v1/webunblocker/*` (buy `POST /orders`) | `$client->webUnblocker->…`             |
| `/api/v1/emails/*` (buy `POST /orders`)       | `$client->emails->…`                   |
| `POST   /api/v1/captcha/solve`                | `$client->captcha->solve(...)`         |
| `GET    /api/v1/captcha/{rates,usage}`        | `$client->captcha->{rates,usage}()`    |
| `GET    /api/v1/orders(/{uuid})`              | `$client->orders->list()` / `get($id)` |
| `GET    /api/v1/pricing`                      | `$client->pricing->all()`              |
| `GET    /api/v1/quotas`                       | `$client->quotas->all()`               |
| `GET    /api/v1/trial`                        | `$client->trial->status()`             |
| `POST   /api/v1/trial/subscribe`              | `$client->trial->subscribe([...])`     |
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

## Changelog

### 0.4.0

- **Repointed the whole SDK to the `/api/v1/*` surface.** Every authenticated
  request path moved off `/api/account/*` onto the consolidated v1 routes.
- **New `numbers` module** — merges the old `activations` + `catalog`
  modules into one. Orders: `create`, `get`, `sms`, `cancel`, `finish`,
  `retry`, `repeat`, `autoRenew`, `batch`, `list`, `summary` under
  `/api/v1/numbers/orders`; catalog: `countries`, `services` (`products`),
  `carriers`, `states`, `pricing` under `/api/v1/numbers/*`.
- **`proxy`** now hits `/api/v1/proxy/*` — buy via `POST /orders`, list
  `/orders`, show `/orders/{uuid}`, extend/`autoRenew` under
  `/orders/{uuid}`, plus `pricing`, `quote`, `locations`, `usage`, `trial`,
  `resetSessions`, and subscription lifecycle. (`packages`, `endpoints`,
  `catalog` removed → `pricing`.)
- **`webUnblocker`** now hits `/api/v1/webunblocker/*` (de-hyphenated) — buy
  via `POST /orders`, list `/orders`, `pricing` (was `packages`), plus
  `quote`, `trial`, subscription lifecycle.
- **`emails`** now hits `/api/v1/emails/*` — buy via `POST /orders`, list
  `/orders`, `pricing` (domains under the `domains` key; was `domains`),
  inbox routes unchanged in shape.
- **`captcha`** keeps `solve`/result and gains `rates()` and `usage()`
  (`GET /api/v1/captcha/usage`).
- **New `orders` module** — global cross-product order history
  (`GET /api/v1/orders(/{uuid})`).
- **New `pricing` module** — all prices in one call (`GET /api/v1/pricing`).
- **New `quotas` module** — remaining prepaid balances
  (`GET /api/v1/quotas`).
- **New `account` module** — `me()` now exposes `abilities` + `features`
  (`GET /api/v1/me`).
- **`wallet`** and **`trial`** repointed to `/api/v1/*`.
- **Removed the `fingerprints` module** — the product is gone.

### 0.3.0

- **New `proxy` module** — residential (per-GB) and static (per-IP) proxies:
  `packages`, `endpoints`, `catalog`, `locations`, `quote`, `purchase`,
  `list`, `extend`, `autoRenew`, `resetSessions`, `usage`, `trial`, and
  residential subscription `pause`/`resume`/`cancel`. Replaces the old
  `proxies` module.
- **New `webUnblocker` module** — request-based access with `packages`,
  `quote`, `purchase`, `trial`, `access`, and subscription
  `pause`/`resume`/`cancel`.
- **New `emails` module** — temporary inboxes: `domains`, `quote`,
  `purchase`, `list`, `get`, `messages`, `markRead`, `release`.
- **New `captcha` module** — blocking `solve()` that resells 2captcha,
  submitting a task and polling `retry_after` until `ready`/`failed`.
- **New `fingerprints` module** — synchronous `generate()` / `random()`
  over the 2captcha Fingerprint API.
- **New `trial` module** — `status()` and `subscribe()` for free-trial
  service enrolment.

### 0.2.0

- Activations, wallet, catalog (countries / services / pricing), and
  webhook signature verification.

## License

MIT
