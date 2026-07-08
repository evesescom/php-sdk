<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;
use InvalidArgumentException;

/**
 * Proxies namespace. Hits ``/api/account/proxies``.
 *
 * Covers residential (metered, per-GB) and static (per-IP) proxies:
 *   - GET  /                       overview (residential access + subscription + orders)
 *   - GET  /packages               residential GB package ladder
 *   - GET  /catalog                static (per-IP) product catalogue
 *   - GET  /quote                  price a residential or static selection
 *   - GET  /locations              targeting (residential geo / static products)
 *   - GET  /usage                  residential usage timeline
 *   - GET  /endpoints              connection endpoints (regions / ports / protocols)
 *   - POST /purchase               buy residential GB or static IPs (idempotent)
 *   - POST /subscription/{action}  cancel|pause|resume the residential subscription
 *   - POST /sessions/reset         reset residential sticky sessions (rotate IPs)
 *   - POST /{uuid}/extend          extend a static order for another period
 *   - POST /{uuid}/auto-renew      toggle auto-extend on a static order
 *
 * Money is integer cents; timestamps are ISO-8601 strings. Wire shapes are
 * snake_case; this module exposes friendlier objects while keeping the original
 * payload on ``->raw`` for anything not modelled.
 */
final class Proxies
{
    public function __construct(private readonly Client $http) {}

    /**
     * The user's proxies: residential connection, subscription and order list.
     *
     * @return object{residential:?object, subscription:?object, orders:array<int,object>, raw:array<string,mixed>}
     */
    public function list(): object
    {
        $res = $this->http->request('GET', '/api/account/proxies');
        $d = self::unwrap($res);

        return (object) [
            'residential' => isset($d['residential']) && is_array($d['residential'])
                ? self::mapResidentialAccess($d['residential'])
                : null,
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
     * Residential GB package ladder (price, per-GB rate, discount).
     *
     * @return object{packages:array<int,array<string,mixed>>, currency:string}
     */
    public function packages(): object
    {
        $res = $this->http->request('GET', '/api/account/proxies/packages');
        $d = self::unwrap($res);

        return (object) [
            'packages' => array_values((array) ($d['packages'] ?? [])),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
        ];
    }

    /**
     * Static (per-IP) catalogue — products / plans / locations with USER prices.
     * ``price_cents`` may be null on a plan, meaning the price must be fetched
     * via ``quote()``.
     *
     * @return object{products:array<int,array<string,mixed>>, currency:string}
     */
    public function catalog(): object
    {
        $res = $this->http->request('GET', '/api/account/proxies/catalog');
        $d = self::unwrap($res);

        return (object) [
            'products' => array_values((array) ($d['products'] ?? [])),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
        ];
    }

    /**
     * Quote a purchase before buying.
     *
     * Residential / metered: ``['type' => 'residential', 'gb' => 5, 'subscription' => true]``.
     * Static: ``['type' => 'isp', 'product_id' => 1, 'plan_id' => 2, 'location_id' => 3, 'quantity' => 1]``.
     *
     * @param array{
     *     type?: string,
     *     gb?: float|int,
     *     subscription?: bool,
     *     product_id?: int,
     *     plan_id?: int,
     *     location_id?: int,
     *     quantity?: int,
     * } $opts
     * @return object{data:array<string,mixed>, raw:array<string,mixed>}
     */
    public function quote(array $opts = []): object
    {
        $type = isset($opts['type']) && is_string($opts['type']) ? $opts['type'] : 'residential';
        $query = ['type' => $type];

        if (isset($opts['gb'])) {
            $query['gb'] = (float) $opts['gb'];
        }
        if (array_key_exists('subscription', $opts)) {
            $query['subscription'] = (bool) $opts['subscription'];
        }
        if (isset($opts['product_id'])) {
            $query['product_id'] = (int) $opts['product_id'];
        }
        if (isset($opts['plan_id'])) {
            $query['plan_id'] = (int) $opts['plan_id'];
        }
        if (isset($opts['location_id'])) {
            $query['location_id'] = (int) $opts['location_id'];
        }
        if (isset($opts['quantity'])) {
            $query['quantity'] = (int) $opts['quantity'];
        }

        $res = $this->http->request('GET', '/api/account/proxies/quote', $query);
        $d = self::unwrap($res);

        return (object) ['data' => $d, 'raw' => $d];
    }

    /**
     * Targeting metadata. Residential returns ``geo``; static returns ``products``.
     *
     * @return object{type:string, geo:mixed, products:array<int,mixed>, raw:array<string,mixed>}
     */
    public function locations(string $type = 'residential'): object
    {
        $res = $this->http->request('GET', '/api/account/proxies/locations', ['type' => $type]);
        $d = self::unwrap($res);

        return (object) [
            'type' => isset($d['type']) && is_string($d['type']) ? $d['type'] : $type,
            'geo' => $d['geo'] ?? null,
            'products' => array_values((array) ($d['products'] ?? [])),
            'raw' => $d,
        ];
    }

    /**
     * Residential usage analytics for a date window (``YYYY-MM-DD``). Both
     * bounds are optional; the API defaults to the last 30 days.
     *
     * @param  array{from?: string, to?: string}  $opts
     * @return object{from:?string, to:?string, raw:array<string,mixed>}
     */
    public function usage(array $opts = []): object
    {
        $query = [];
        if (isset($opts['from']) && $opts['from'] !== '') {
            $query['from'] = (string) $opts['from'];
        }
        if (isset($opts['to']) && $opts['to'] !== '') {
            $query['to'] = (string) $opts['to'];
        }

        $res = $this->http->request('GET', '/api/account/proxies/usage', $query);
        $d = self::unwrap($res);

        return (object) [
            'from' => isset($d['from']) && is_string($d['from']) ? $d['from'] : null,
            'to' => isset($d['to']) && is_string($d['to']) ? $d['to'] : null,
            'raw' => $d,
        ];
    }

    /**
     * Connection endpoints for residential proxies: the targetable ``regions``
     * (each with a ``code``/``host``/``label``), the available ``ports`` per
     * protocol, and the supported ``protocols``.
     *
     * @return object{regions:array<int,object>, ports:array<string,array<int,int>>, protocols:array<int,string>, raw:array<string,mixed>}
     */
    public function endpoints(): object
    {
        $res = $this->http->request('GET', '/api/account/proxies/endpoints');
        $d = self::unwrap($res);

        return (object) [
            'regions' => array_map(
                [self::class, 'mapRegion'],
                array_values((array) ($d['regions'] ?? [])),
            ),
            'ports' => self::mapPorts($d['ports'] ?? []),
            'protocols' => array_values(array_filter(
                (array) ($d['protocols'] ?? []),
                'is_string',
            )),
            'raw' => $d,
        ];
    }

    /**
     * Buy proxies (residential GB top-up or static IPs). Returns the created
     * order. Pass ``idempotency_key`` to make the purchase safely retryable —
     * it is sent as the ``Idempotency-Key`` header.
     *
     * @param array{
     *     type: string,
     *     gb?: float|int,
     *     subscription?: bool,
     *     product_id?: int,
     *     plan_id?: int,
     *     location_id?: int,
     *     location_name?: string,
     *     quantity?: int,
     *     idempotency_key?: string,
     * } $req
     */
    public function purchase(array $req): object
    {
        $type = isset($req['type']) ? (string) $req['type'] : '';
        if ($type === '') {
            throw new InvalidArgumentException('type is required');
        }

        $body = ['type' => $type];
        if (isset($req['gb'])) {
            $body['gb'] = (float) $req['gb'];
        }
        if (array_key_exists('subscription', $req)) {
            $body['subscription'] = (bool) $req['subscription'];
        }
        if (isset($req['product_id'])) {
            $body['product_id'] = (int) $req['product_id'];
        }
        if (isset($req['plan_id'])) {
            $body['plan_id'] = (int) $req['plan_id'];
        }
        if (isset($req['location_id'])) {
            $body['location_id'] = (int) $req['location_id'];
        }
        if (isset($req['location_name']) && $req['location_name'] !== '') {
            $body['location_name'] = (string) $req['location_name'];
        }
        if (isset($req['quantity'])) {
            $body['quantity'] = (int) $req['quantity'];
        }

        $headers = [];
        if (isset($req['idempotency_key']) && $req['idempotency_key'] !== '') {
            $headers['Idempotency-Key'] = (string) $req['idempotency_key'];
        }

        $res = $this->http->request('POST', '/api/account/proxies/purchase', body: $body, headers: $headers);

        return self::mapOrder(self::unwrap($res));
    }

    /** Cancel the residential subscription (stop auto-renewal; traffic stays). */
    public function cancelSubscription(): object
    {
        return $this->subscriptionAction('cancel');
    }

    /** Pause the residential subscription (skip renewals until resumed). */
    public function pauseSubscription(): object
    {
        return $this->subscriptionAction('pause');
    }

    /** Resume the residential subscription (next renewal a month out). */
    public function resumeSubscription(): object
    {
        return $this->subscriptionAction('resume');
    }

    /**
     * Extend a static (per-IP) order for another period (re-charges its price).
     */
    public function extend(string $orderUuid, int $days = 30): object
    {
        $res = $this->http->request(
            'POST',
            '/api/account/proxies/'.rawurlencode($orderUuid).'/extend',
            body: ['days' => $days],
        );

        return self::mapOrder(self::unwrap($res));
    }

    /** Toggle auto-renew (auto_extend) on a per-IP order. */
    public function autoRenew(string $orderUuid, bool $enabled): object
    {
        $res = $this->http->request(
            'POST',
            '/api/account/proxies/'.rawurlencode($orderUuid).'/auto-renew',
            body: ['enabled' => $enabled],
        );

        return self::mapOrder(self::unwrap($res));
    }

    /**
     * Reset the user's residential sticky sessions; the next request rotates to
     * fresh IPs. Requires a provisioned residential sub-user.
     */
    public function resetSessions(): void
    {
        $this->http->request('POST', '/api/account/proxies/sessions/reset');
    }

    private function subscriptionAction(string $action): object
    {
        $res = $this->http->request('POST', '/api/account/proxies/subscription/'.$action);

        return self::mapSubscription(self::unwrap($res));
    }

    // ── mappers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $d
     */
    private static function mapResidentialAccess(array $d): object
    {
        return (object) [
            'host' => isset($d['host']) && is_string($d['host']) ? $d['host'] : '',
            'ports' => (array) ($d['ports'] ?? []),
            'username' => isset($d['username']) && is_string($d['username']) ? $d['username'] : '',
            'password' => isset($d['password']) && is_string($d['password']) ? $d['password'] : '',
            'example' => isset($d['example']) && is_string($d['example']) ? $d['example'] : null,
            'curl' => isset($d['curl']) && is_string($d['curl']) ? $d['curl'] : null,
            'trafficGbAvailable' => self::floatOr($d['traffic_gb_available'] ?? null, 0.0),
            'trafficGbUsed' => self::floatOr($d['traffic_gb_used'] ?? null, 0.0),
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
            'gb' => self::floatOr($d['gb'] ?? null, 0.0),
            'discountPct' => self::intOr($d['discount_pct'] ?? null, 0),
            'nextRenewsAt' => isset($d['next_renews_at']) && is_string($d['next_renews_at']) ? $d['next_renews_at'] : null,
            'renewFailures' => self::intOr($d['renew_failures'] ?? null, 0),
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapRegion(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'code' => isset($d['code']) && is_string($d['code']) ? $d['code'] : '',
            'host' => isset($d['host']) && is_string($d['host']) ? $d['host'] : '',
            'label' => isset($d['label']) && is_string($d['label']) ? $d['label'] : null,
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     * @return array<string,array<int,int>>
     */
    private static function mapPorts(mixed $m): array
    {
        if (! is_array($m)) {
            return [];
        }

        $out = [];
        foreach ($m as $protocol => $ports) {
            if (! is_string($protocol)) {
                continue;
            }
            $out[$protocol] = array_values(array_map(
                'intval',
                array_filter((array) $ports, static fn ($p): bool => is_int($p) || is_float($p)),
            ));
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapOrder(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'uuid' => isset($d['uuid']) && is_string($d['uuid']) ? $d['uuid'] : '',
            'type' => isset($d['type']) && is_string($d['type']) ? $d['type'] : null,
            'kind' => isset($d['kind']) && is_string($d['kind']) ? $d['kind'] : null,
            'gb' => isset($d['gb']) && (is_int($d['gb']) || is_float($d['gb'])) ? (float) $d['gb'] : null,
            'quantity' => self::intOr($d['quantity'] ?? null, 0),
            'location' => $d['location'] ?? null,
            'status' => isset($d['status']) && is_string($d['status']) ? $d['status'] : 'pending',
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
            'proxies' => $d['proxies'] ?? null,
            'autoExtend' => isset($d['auto_extend']) && is_bool($d['auto_extend']) ? $d['auto_extend'] : false,
            'extendable' => isset($d['extendable']) && is_bool($d['extendable']) ? $d['extendable'] : false,
            'expiresAt' => isset($d['expires_at']) && is_string($d['expires_at']) ? $d['expires_at'] : null,
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

    private static function floatOr(mixed $value, float $fallback): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }
}
