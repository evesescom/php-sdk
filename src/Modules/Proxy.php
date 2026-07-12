<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Proxy namespace — buy and manage residential (metered, per-GB) and static
 * (per-IP: ISP / datacenter / IPv6 / sneaker / mobile) proxies. Hits
 * ``/api/v1/proxy/*``.
 *
 * The provider stays invisible: connection details are returned under the
 * white-label host.
 */
final class Proxy
{
    public function __construct(private readonly Client $http) {}

    /**
     * Consolidated price list (residential GB ladder + static per-IP catalogue).
     *
     * @return array<string,mixed>
     */
    public function pricing(): array
    {
        return (array) $this->http->request('GET', '/api/v1/proxy/pricing');
    }

    /**
     * Available targeting for a proxy type: residential geo (countries/regions/
     * sets) or a static family's catalogue locations.
     *
     * @return array<string,mixed>
     */
    public function locations(string $type = 'residential'): array
    {
        return (array) $this->http->request('GET', '/api/v1/proxy/locations', ['type' => $type]);
    }

    /**
     * Estimate a purchase before buying (residential GB or a static selection).
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
     * @return array<string,mixed>
     */
    public function quote(array $opts = []): array
    {
        $type = (string) ($opts['type'] ?? 'residential');
        $query = ['type' => $type];
        if ($type === 'residential') {
            $query['gb'] = (float) ($opts['gb'] ?? 0);
            if (! empty($opts['subscription'])) {
                $query['subscription'] = 'true';
            }
        } else {
            $query['product_id'] = (int) ($opts['product_id'] ?? 0);
            $query['plan_id'] = (int) ($opts['plan_id'] ?? 0);
            $query['location_id'] = (int) ($opts['location_id'] ?? 0);
            $query['quantity'] = (int) ($opts['quantity'] ?? 1);
        }

        return (array) $this->http->request('GET', '/api/v1/proxy/quote', $query);
    }

    /**
     * Buy proxies (residential GB top-up or static IPs). Returns the order object.
     *
     * @param array{
     *     type?: string,
     *     gb?: float|int,
     *     subscription?: bool,
     *     product_id?: int,
     *     plan_id?: int,
     *     location_id?: int,
     *     location_name?: string,
     *     quantity?: int,
     *     idempotency_key?: string,
     * } $opts
     */
    public function purchase(array $opts = []): object
    {
        $type = (string) ($opts['type'] ?? 'residential');
        $headers = [];
        if (isset($opts['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $opts['idempotency_key'];
        }

        $body = ['type' => $type];
        if ($type === 'residential') {
            $body['gb'] = (float) ($opts['gb'] ?? 0);
            if (! empty($opts['subscription'])) {
                $body['subscription'] = true;
            }
        } else {
            $body['product_id'] = (int) ($opts['product_id'] ?? 0);
            $body['plan_id'] = (int) ($opts['plan_id'] ?? 0);
            $body['location_id'] = (int) ($opts['location_id'] ?? 0);
            if (isset($opts['location_name'])) {
                $body['location_name'] = (string) $opts['location_name'];
            }
            $body['quantity'] = (int) ($opts['quantity'] ?? 1);
        }

        $res = (array) $this->http->request('POST', '/api/v1/proxy/orders', null, $body, $headers);

        return self::mapOrder($res);
    }

    /**
     * The user's proxies: residential sub-user connection, subscription, and
     * per-IP orders.
     */
    public function list(): object
    {
        $res = (array) $this->http->request('GET', '/api/v1/proxy/orders');

        $residential = isset($res['residential']) && is_array($res['residential']) ? (object) $res['residential'] : null;
        $subscription = isset($res['subscription']) && is_array($res['subscription'])
            ? self::mapSubscription($res['subscription'])
            : null;
        $ordersRaw = isset($res['orders']) && is_array($res['orders']) ? $res['orders'] : [];
        $orders = array_values(array_map(
            static fn (mixed $o): object => self::mapOrder(is_array($o) ? $o : []),
            $ordersRaw,
        ));

        return (object) [
            'residential' => $residential,
            'subscription' => $subscription,
            'orders' => $orders,
        ];
    }

    /**
     * Show a single per-IP order by UUID.
     */
    public function get(string $orderUuid): object
    {
        $res = (array) $this->http->request('GET', '/api/v1/proxy/orders/'.rawurlencode($orderUuid));

        return self::mapOrder($res);
    }

    /**
     * Extend a static (per-IP) order for another period (re-charges its price).
     */
    public function extend(string $orderUuid, int $days = 30): object
    {
        $res = (array) $this->http->request(
            'POST',
            '/api/v1/proxy/orders/'.rawurlencode($orderUuid).'/extend',
            null,
            ['days' => $days],
        );

        return self::mapOrder($res);
    }

    /** Toggle auto-renew (auto_extend) on a per-IP order. */
    public function autoRenew(string $orderUuid, bool $enabled): object
    {
        $res = (array) $this->http->request(
            'POST',
            '/api/v1/proxy/orders/'.rawurlencode($orderUuid).'/auto-renew',
            null,
            ['enabled' => $enabled],
        );

        return self::mapOrder($res);
    }

    /**
     * Reset the residential sticky sessions (next request rotates IPs).
     *
     * @return array<string,mixed>
     */
    public function resetSessions(): array
    {
        return (array) $this->http->request('POST', '/api/v1/proxy/sessions/reset');
    }

    /**
     * Residential usage analytics — daily traffic/requests timeline + top hosts.
     *
     * @param  array{from?: string, to?: string}  $opts
     * @return array<string,mixed>
     */
    public function usage(array $opts = []): array
    {
        $query = [];
        if (isset($opts['from'])) {
            $query['from'] = (string) $opts['from'];
        }
        if (isset($opts['to'])) {
            $query['to'] = (string) $opts['to'];
        }

        return (array) $this->http->request('GET', '/api/v1/proxy/usage', $query ?: null);
    }

    /**
     * Activate the free proxy trial (no charge; one-time per account).
     *
     * @return array<string,mixed>
     */
    public function trial(): array
    {
        return (array) $this->http->request('POST', '/api/v1/proxy/trial');
    }

    /** Cancel the residential subscription (stop auto-renewal; traffic stays). */
    public function subscriptionCancel(): object
    {
        return $this->subscriptionAction('cancel');
    }

    /** Pause the residential subscription (skip renewals until resumed). */
    public function subscriptionPause(): object
    {
        return $this->subscriptionAction('pause');
    }

    /** Resume the residential subscription (next renewal a month out). */
    public function subscriptionResume(): object
    {
        return $this->subscriptionAction('resume');
    }

    private function subscriptionAction(string $action): object
    {
        $res = (array) $this->http->request('POST', '/api/v1/proxy/subscription/'.$action);

        return self::mapSubscription($res);
    }

    /**
     * @param  array<string,mixed>  $r
     */
    private static function mapOrder(array $r): object
    {
        return (object) [
            'uuid' => isset($r['uuid']) ? (string) $r['uuid'] : '',
            'type' => isset($r['type']) ? (string) $r['type'] : '',
            'kind' => isset($r['kind']) && is_string($r['kind']) ? $r['kind'] : null,
            'gb' => isset($r['gb']) && (is_int($r['gb']) || is_float($r['gb'])) ? (float) $r['gb'] : null,
            'quantity' => isset($r['quantity']) && is_int($r['quantity']) ? $r['quantity'] : null,
            'location' => isset($r['location']) && is_string($r['location']) ? $r['location'] : null,
            'status' => isset($r['status']) ? (string) $r['status'] : '',
            'priceCents' => isset($r['price_cents']) ? (int) $r['price_cents'] : 0,
            'currency' => isset($r['currency']) && is_string($r['currency']) ? $r['currency'] : 'USD',
            'proxies' => $r['proxies'] ?? null,
            'autoExtend' => ($r['auto_extend'] ?? false) === true,
            'extendable' => ($r['extendable'] ?? false) === true,
            'expiresAt' => isset($r['expires_at']) && is_string($r['expires_at']) ? $r['expires_at'] : null,
            'createdAt' => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : null,
            'raw' => $r,
        ];
    }

    /**
     * @param  array<string,mixed>  $r
     */
    private static function mapSubscription(array $r): object
    {
        return (object) [
            'status' => isset($r['status']) ? (string) $r['status'] : '',
            'gb' => isset($r['gb']) && (is_int($r['gb']) || is_float($r['gb'])) ? (float) $r['gb'] : 0.0,
            'discountPct' => isset($r['discount_pct']) ? (int) $r['discount_pct'] : 0,
            'nextRenewsAt' => isset($r['next_renews_at']) && is_string($r['next_renews_at']) ? $r['next_renews_at'] : null,
            'renewFailures' => isset($r['renew_failures']) ? (int) $r['renew_failures'] : 0,
        ];
    }
}
