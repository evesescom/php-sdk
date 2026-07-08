<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Web Unblocker namespace. Hits ``/api/account/web-unblocker``.
 *
 * An anti-bot scraping endpoint billed per successful request:
 *   - GET  /                       overview (access credentials + subscription + orders)
 *   - GET  /packages               request-bundle ladder
 *   - GET  /quote?requests=        price a bundle before buying
 *   - POST /purchase               buy a request bundle (idempotent)
 *   - POST /subscription/{action}  cancel|pause|resume the subscription
 *
 * Money is integer cents; timestamps are ISO-8601 strings.
 */
final class WebUnblocker
{
    public function __construct(private readonly Client $http) {}

    /**
     * The user's Web Unblocker: connection credentials, subscription and orders.
     *
     * @return object{access:?object, subscription:?object, orders:array<int,object>, raw:array<string,mixed>}
     */
    public function list(): object
    {
        $res = $this->http->request('GET', '/api/account/web-unblocker');
        $d = self::unwrap($res);

        return (object) [
            'access' => isset($d['access']) && is_array($d['access']) ? self::mapAccess($d['access']) : null,
            'subscription' => isset($d['subscription']) && is_array($d['subscription'])
                ? self::mapSubscription($d['subscription'])
                : null,
            'orders' => array_map(
                [self::class, 'mapOrder'],
                array_values((array) ($d['orders'] ?? [])),
            ),
            'raw' => $d,
        ];
    }

    /**
     * Request-bundle ladder (price, per-1k rate, discount).
     *
     * @return object{packages:array<int,array<string,mixed>>, currency:string}
     */
    public function packages(): object
    {
        $res = $this->http->request('GET', '/api/account/web-unblocker/packages');
        $d = self::unwrap($res);

        return (object) [
            'packages' => array_values((array) ($d['packages'] ?? [])),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
        ];
    }

    /**
     * Quote a bundle before buying. ``subscription`` opts into recurring pricing.
     *
     * @return object{product:string, requests:int, unit:?string, priceCents:int, per1kCents:int, currency:string, raw:array<string,mixed>}
     */
    public function quote(int $requests, bool $subscription = false): object
    {
        $query = ['requests' => $requests];
        if ($subscription) {
            $query['subscription'] = true;
        }

        $res = $this->http->request('GET', '/api/account/web-unblocker/quote', $query);
        $d = self::unwrap($res);

        return (object) [
            'product' => isset($d['product']) && is_string($d['product']) ? $d['product'] : 'web_unblocker',
            'requests' => self::intOr($d['requests'] ?? null, $requests),
            'unit' => isset($d['unit']) && is_string($d['unit']) ? $d['unit'] : null,
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'per1kCents' => self::intOr($d['per_1k_cents'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
            'raw' => $d,
        ];
    }

    /**
     * Buy a request bundle (top up the user's pool). Pass ``idempotency_key``
     * to make the purchase safely retryable.
     *
     * @param array{requests: int, subscription?: bool, idempotency_key?: string} $req
     */
    public function purchase(array $req): object
    {
        $body = ['requests' => (int) ($req['requests'] ?? 0)];
        if (array_key_exists('subscription', $req)) {
            $body['subscription'] = (bool) $req['subscription'];
        }

        $headers = [];
        if (isset($req['idempotency_key']) && $req['idempotency_key'] !== '') {
            $headers['Idempotency-Key'] = (string) $req['idempotency_key'];
        }

        $res = $this->http->request('POST', '/api/account/web-unblocker/purchase', body: $body, headers: $headers);

        return self::mapOrder(self::unwrap($res));
    }

    /** Cancel the subscription (stop auto-renewal; requests stay). */
    public function cancelSubscription(): object
    {
        return $this->subscriptionAction('cancel');
    }

    /** Pause the subscription (skip renewals until resumed). */
    public function pauseSubscription(): object
    {
        return $this->subscriptionAction('pause');
    }

    /** Resume the subscription (next renewal a month out). */
    public function resumeSubscription(): object
    {
        return $this->subscriptionAction('resume');
    }

    private function subscriptionAction(string $action): object
    {
        $res = $this->http->request('POST', '/api/account/web-unblocker/subscription/'.$action);

        return self::mapSubscription(self::unwrap($res));
    }

    // ── mappers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $d
     */
    private static function mapAccess(array $d): object
    {
        return (object) [
            'host' => isset($d['host']) && is_string($d['host']) ? $d['host'] : '',
            'port' => self::intOr($d['port'] ?? null, 0),
            'username' => isset($d['username']) && is_string($d['username']) ? $d['username'] : '',
            'password' => isset($d['password']) && is_string($d['password']) ? $d['password'] : '',
            'example' => isset($d['example']) && is_string($d['example']) ? $d['example'] : null,
            'curl' => isset($d['curl']) && is_string($d['curl']) ? $d['curl'] : null,
            'requestsPurchased' => self::intOr($d['requests_purchased'] ?? null, 0),
            'requestsUsed' => self::intOr($d['requests_used'] ?? null, 0),
            'requestsRemaining' => self::intOr($d['requests_remaining'] ?? null, 0),
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>  $d
     */
    private static function mapSubscription(array $d): object
    {
        return (object) [
            'status' => isset($d['status']) && is_string($d['status']) ? $d['status'] : '',
            'requests' => self::intOr($d['requests'] ?? null, 0),
            'discountPct' => self::intOr($d['discount_pct'] ?? null, 0),
            'nextRenewsAt' => isset($d['next_renews_at']) && is_string($d['next_renews_at']) ? $d['next_renews_at'] : null,
            'renewFailures' => self::intOr($d['renew_failures'] ?? null, 0),
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapOrder(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'uuid' => isset($d['uuid']) && is_string($d['uuid']) ? $d['uuid'] : '',
            'product' => isset($d['product']) && is_string($d['product']) ? $d['product'] : 'web_unblocker',
            'requests' => self::intOr($d['requests'] ?? null, 0),
            'status' => isset($d['status']) && is_string($d['status']) ? $d['status'] : 'pending',
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
            'createdAt' => isset($d['created_at']) && is_string($d['created_at']) ? $d['created_at'] : null,
            'raw' => $d,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function unwrap(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return is_array($payload) ? $payload : [];
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return is_int($value) ? $value : $fallback;
    }
}
