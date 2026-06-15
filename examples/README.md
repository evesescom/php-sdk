# `eveses/sdk` (PHP) — examples

Three runnable scripts that exercise the SDK end-to-end. PHP 8.3+, no
framework, just `require_once 'vendor/autoload.php'`.

| File | What it shows |
| --- | --- |
| `quickstart.php` | Construct the client, check wallet balance, list services, buy ONE activation with an idempotency key. |
| `buy-and-poll.php` | Full activation lifecycle: create → poll SMS every 5s for 5 min → `finish()` (or `cancel()` on Ctrl-C / timeout). |
| `webhook-server.php` | Minimal HTTP endpoint (PHP's built-in dev server) that verifies `X-Eveses-Signature` with `Webhooks::verify` and prints the parsed payload. |

## Prerequisites

```bash
cd sdk/php
composer install                       # installs the SDK + autoloader

# Get a Sanctum API-key token (kind=api_key) from the Eveses dashboard.
export EVESES_API_KEY=sk_live_xxx

# For the webhook server only:
export EVESES_WEBHOOK_SECRET=whsec_xxx
```

Run any example:

```bash
php examples/quickstart.php
php examples/buy-and-poll.php
php -S 0.0.0.0:8787 examples/webhook-server.php   # webhook receiver
```
