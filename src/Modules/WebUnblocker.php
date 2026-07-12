<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Web Unblocker namespace — buy and manage request-based web-unblocker access,
 * subscriptions, and free trial. Hits ``/api/v1/webunblocker/*``.
 */
final class WebUnblocker
{
    public function __construct(private readonly Client $http) {}

    /**
     * Available request-count packages with pricing.
     *
     * @return array<string,mixed>
     */
    public function pricing(): array
    {
        return (array) $this->http->request('GET', '/api/v1/webunblocker/pricing');
    }

    /**
     * Estimate a purchase: price for a given request count, optionally as a
     * subscription.
     *
     * @return array<string,mixed>
     */
    public function quote(int $requests, bool $subscription = false): array
    {
        $query = ['requests' => $requests];
        if ($subscription) {
            $query['subscription'] = '1';
        }

        return (array) $this->http->request('GET', '/api/v1/webunblocker/quote', $query);
    }

    /**
     * Purchase web-unblocker access. Returns the created order object.
     */
    public function purchase(int $requests, bool $subscription = false, ?string $idempotencyKey = null): object
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = ['requests' => $requests];
        if ($subscription) {
            $body['subscription'] = true;
        }

        $res = (array) $this->http->request('POST', '/api/v1/webunblocker/orders', null, $body, $headers);

        return self::mapAccess($res);
    }

    /**
     * Activate the free trial (no charge; one-time per account).
     */
    public function trial(): object
    {
        $res = (array) $this->http->request('POST', '/api/v1/webunblocker/trial');

        return self::mapAccess($res);
    }

    /**
     * Current web-unblocker access: request budget, credentials, subscription.
     *
     * @return array<string,mixed>
     */
    public function access(): array
    {
        return (array) $this->http->request('GET', '/api/v1/webunblocker/orders');
    }

    /** Cancel the web-unblocker subscription (stop auto-renewal). */
    public function subscriptionCancel(): object
    {
        return $this->subscriptionAction('cancel');
    }

    /** Pause the web-unblocker subscription (skip renewals until resumed). */
    public function subscriptionPause(): object
    {
        return $this->subscriptionAction('pause');
    }

    /** Resume the web-unblocker subscription (next renewal a month out). */
    public function subscriptionResume(): object
    {
        return $this->subscriptionAction('resume');
    }

    private function subscriptionAction(string $action): object
    {
        $res = (array) $this->http->request('POST', '/api/v1/webunblocker/subscription/'.$action);

        return self::mapSubscription($res);
    }

    /**
     * @param  array<string,mixed>  $r
     */
    private static function mapAccess(array $r): object
    {
        return (object) [
            'uuid' => isset($r['uuid']) ? (string) $r['uuid'] : '',
            'requests' => isset($r['requests']) ? (int) $r['requests'] : 0,
            'requestsUsed' => isset($r['requests_used']) ? (int) $r['requests_used'] : 0,
            'subscription' => ($r['subscription'] ?? false) === true,
            'status' => isset($r['status']) ? (string) $r['status'] : '',
            'priceCents' => isset($r['price_cents']) ? (int) $r['price_cents'] : 0,
            'currency' => isset($r['currency']) && is_string($r['currency']) ? $r['currency'] : 'USD',
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
            'requests' => isset($r['requests']) && is_int($r['requests']) ? $r['requests'] : 0,
            'discountPct' => isset($r['discount_pct']) ? (int) $r['discount_pct'] : 0,
            'nextRenewsAt' => isset($r['next_renews_at']) && is_string($r['next_renews_at']) ? $r['next_renews_at'] : null,
            'renewFailures' => isset($r['renew_failures']) ? (int) $r['renew_failures'] : 0,
        ];
    }
}
