# eveses/sdk (PHP SDK)

Official PHP SDK for the [Eveses](https://eveses.com) developer API.
Activations, wallet, catalog (countries / services / pricing), proxies,
web-unblocker, temporary emails, captcha-solving, fingerprints, free trials,
and webhook signature verification.

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

Buy and manage residential (metered, per-GB) and static (per-IP: ISP /
datacenter / IPv6 / sneaker / mobile) proxies. The upstream provider stays
invisible — connection details come back under the white-label host.

```php
// Browse
$packages  = $client->proxy->packages();                    // residential GB ladder
$endpoints = $client->proxy->endpoints();                   // white-label entry hosts + ports
$catalog   = $client->proxy->catalog();                     // static (per-IP) products/plans
$locations = $client->proxy->locations('residential');      // targeting for a type

// Estimate then buy
$quote = $client->proxy->quote(['type' => 'residential', 'gb' => 5]);
$order = $client->proxy->purchase([
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
$mine = $client->proxy->list();                             // residential + subscription + per-IP orders
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
$packages = $client->webUnblocker->packages();
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
$domains = $client->emails->domains();                      // optionally ->domains('site.com')
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

## Fingerprints

Resells 2captcha's Fingerprint API, billed pay-per-use from the wallet
(count-on-success). Unlike captcha solving this is synchronous — one request
returns a complete fingerprint.

```php
$out = $client->fingerprints->generate(['os' => 'windows', 'browser' => 'chrome']);
$out = $client->fingerprints->random();                     // random, optionally filtered
// $out->fingerprint (array) / $out->priceMicroUsd
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
| `GET    /api/account/proxies*`               | `$client->proxy->…`                    |
| `POST   /api/account/proxies/purchase`       | `$client->proxy->purchase([...])`      |
| `GET    /api/account/web-unblocker*`         | `$client->webUnblocker->…`             |
| `GET    /api/account/emails*`                | `$client->emails->…`                   |
| `POST   /api/account/captcha/solve`          | `$client->captcha->solve(...)`         |
| `POST   /api/account/fingerprints/*`         | `$client->fingerprints->…`             |
| `GET    /api/account/trial`                  | `$client->trial->status()`             |
| `POST   /api/account/trial/subscribe`        | `$client->trial->subscribe([...])`     |
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
