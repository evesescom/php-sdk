<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;
use InvalidArgumentException;

/**
 * Numbers namespace — the consolidated SMS surface (v0.3.0's ``activations``
 * + ``catalog`` merged into one module). Hits ``/api/v1/numbers/*``.
 *
 * Order lifecycle:
 *   - POST /api/v1/numbers/orders                       (create)
 *   - GET  /api/v1/numbers/orders/{uuid}                (get)
 *   - GET  /api/v1/numbers/orders/{uuid}/sms            (sms)
 *   - POST /api/v1/numbers/orders/{uuid}/{cancel,finish,retry,repeat,auto-renew}
 *   - POST /api/v1/numbers/orders/batch                 (batch)
 *   - GET  /api/v1/numbers/orders(/summary)            (list / summary)
 *
 * Catalog (read-only, drives order-creation UX):
 *   - GET  /api/v1/numbers/countries?mode=
 *   - GET  /api/v1/numbers/products?mode=               (the "services" list)
 *   - GET  /api/v1/numbers/carriers?mode=&country=
 *   - GET  /api/v1/numbers/states?mode=&country=
 *   - GET  /api/v1/numbers/pricing?mode=&country=&product=&duration=
 */
final class Numbers
{
    public function __construct(private readonly Client $http) {}

    // ---------------------------------------------------------- orders --

    /**
     * Provision a number for a country/service. Returns the created order
     * as an object with ``orderId``, ``status``, ``phone``, ``priceCents``,
     * ``expiresAt``, ``createdAt`` and the original ``raw`` payload.
     *
     * @param array{
     *     country: string,
     *     service: string,
     *     mode?: string,
     *     duration_minutes?: int,
     *     idempotency_key?: string,
     *     max_price_cents?: int,
     * } $req
     */
    public function create(array $req): object
    {
        $body = [
            'mode' => $req['mode'] ?? 'activation',
            'country' => $req['country'] ?? '',
            'service' => $req['service'] ?? '',
        ];
        if (isset($req['duration_minutes'])) {
            $body['duration_minutes'] = (int) $req['duration_minutes'];
        }
        if (isset($req['idempotency_key'])) {
            $body['idempotency_key'] = (string) $req['idempotency_key'];
        }
        if (isset($req['max_price_cents'])) {
            $body['max_price_cents'] = (int) $req['max_price_cents'];
        }

        $headers = [];
        if (isset($req['idempotency_key']) && $req['idempotency_key'] !== '') {
            $headers['Idempotency-Key'] = (string) $req['idempotency_key'];
        }

        $res = $this->http->request(
            method: 'POST',
            path: '/api/v1/numbers/orders',
            body: $body,
            headers: $headers,
        );

        return self::mapOrder(self::unwrap($res));
    }

    public function get(string $orderId): object
    {
        $res = $this->http->request('GET', '/api/v1/numbers/orders/'.rawurlencode($orderId));

        return self::mapOrder(self::unwrap($res));
    }

    public function cancel(string $orderId): object
    {
        return $this->action($orderId, 'cancel');
    }

    public function finish(string $orderId): object
    {
        return $this->action($orderId, 'finish');
    }

    /** Ask the provider for another code on the same number. */
    public function retry(string $orderId): object
    {
        return $this->action($orderId, 'retry');
    }

    /** Re-order the same number/service (a fresh order for the same target). */
    public function repeat(string $orderId): object
    {
        return $this->action($orderId, 'repeat');
    }

    /** Toggle auto-renew on a rental order. */
    public function autoRenew(string $orderId, bool $enabled): object
    {
        $res = $this->http->request(
            'POST',
            '/api/v1/numbers/orders/'.rawurlencode($orderId).'/auto-renew',
            null,
            ['enabled' => $enabled],
        );

        return self::mapOrder(self::unwrap($res));
    }

    private function action(string $orderId, string $verb): object
    {
        $res = $this->http->request('POST', '/api/v1/numbers/orders/'.rawurlencode($orderId).'/'.$verb);

        return self::mapOrder(self::unwrap($res));
    }

    /**
     * Get all SMS for an order. Returns object with ``orderId``, ``stored``,
     * ``fresh`` (each an array of objects with ``id``, ``text``, ``sender``,
     * ``receivedAt``).
     */
    public function sms(string $orderId): object
    {
        $res = $this->http->request('GET', '/api/v1/numbers/orders/'.rawurlencode($orderId).'/sms');
        $data = self::unwrap($res);

        return (object) [
            'orderId' => (string) ($data['order_id'] ?? $orderId),
            'stored' => array_map([self::class, 'mapSms'], (array) ($data['stored'] ?? [])),
            'fresh' => array_map([self::class, 'mapSms'], (array) ($data['fresh'] ?? [])),
        ];
    }

    /**
     * Create several orders in one request. Returns the raw batch payload.
     *
     * @param  array<int,array<string,mixed>>  $orders
     * @param  array{idempotency_key?:string}  $opts
     * @return array<string,mixed>
     */
    public function batch(array $orders, array $opts = []): array
    {
        $headers = [];
        if (isset($opts['idempotency_key']) && $opts['idempotency_key'] !== '') {
            $headers['Idempotency-Key'] = (string) $opts['idempotency_key'];
        }

        return (array) $this->http->request(
            'POST',
            '/api/v1/numbers/orders/batch',
            null,
            ['orders' => array_values($orders)],
            $headers ?: null,
        );
    }

    /**
     * List the account's number orders.
     *
     * @param  array<string,scalar|null>  $query
     * @return array<string,mixed>
     */
    public function list(array $query = []): array
    {
        return (array) $this->http->request('GET', '/api/v1/numbers/orders', $query ?: null);
    }

    /**
     * Aggregate summary of the account's number orders.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return (array) $this->http->request('GET', '/api/v1/numbers/orders/summary');
    }

    // --------------------------------------------------------- catalog --

    /**
     * @param  array{mode?: string}  $opts
     * @return object{mode:string, countries: array<int,string>}
     */
    public function countries(array $opts = []): object
    {
        $mode = (string) ($opts['mode'] ?? 'activation');
        $res = $this->http->request(
            method: 'GET',
            path: '/api/v1/numbers/countries',
            query: ['mode' => $mode],
        );
        $d = self::unwrap($res);
        $list = isset($d['countries']) && is_array($d['countries']) ? $d['countries'] : [];

        return (object) [
            'mode' => isset($d['mode']) && is_string($d['mode']) ? $d['mode'] : $mode,
            'countries' => array_values(array_map('strval', $list)),
        ];
    }

    /**
     * @param  array{mode?: string, country?: string, currency?: string}  $opts
     * @return object{mode:string, services: array<int,string>, country:?string, currency:?string}
     */
    public function services(array $opts = []): object
    {
        $mode = (string) ($opts['mode'] ?? 'activation');
        $res = $this->http->request(
            method: 'GET',
            path: '/api/v1/numbers/products',
            query: ['mode' => $mode],
        );
        $d = self::unwrap($res);
        $list = isset($d['products']) && is_array($d['products']) ? $d['products'] : [];

        $country = isset($opts['country']) && is_string($opts['country']) && $opts['country'] !== ''
            ? strtolower($opts['country'])
            : null;
        $currency = isset($opts['currency']) && is_string($opts['currency']) && $opts['currency'] !== ''
            ? strtoupper($opts['currency'])
            : null;

        return (object) [
            'mode' => isset($d['mode']) && is_string($d['mode']) ? $d['mode'] : $mode,
            'services' => array_values(array_map('strval', $list)),
            'country' => $country,
            'currency' => $currency,
        ];
    }

    /**
     * Carriers/operators available for a country (per mode).
     *
     * @param  array{mode?: string, country?: string}  $opts
     * @return array<string,mixed>
     */
    public function carriers(array $opts = []): array
    {
        $query = ['mode' => (string) ($opts['mode'] ?? 'activation')];
        if (isset($opts['country']) && is_string($opts['country']) && $opts['country'] !== '') {
            $query['country'] = strtolower($opts['country']);
        }

        return (array) $this->http->request('GET', '/api/v1/numbers/carriers', $query);
    }

    /**
     * States/regions available for a country (per mode).
     *
     * @param  array{mode?: string, country?: string}  $opts
     * @return array<string,mixed>
     */
    public function states(array $opts = []): array
    {
        $query = ['mode' => (string) ($opts['mode'] ?? 'activation')];
        if (isset($opts['country']) && is_string($opts['country']) && $opts['country'] !== '') {
            $query['country'] = strtolower($opts['country']);
        }

        return (array) $this->http->request('GET', '/api/v1/numbers/states', $query);
    }

    /**
     * Pricing for a country/service pair.
     *
     * @param array{
     *     country: string,
     *     service: string,
     *     mode?: string,
     *     currency?: string,
     *     duration_minutes?: int,
     * } $opts
     */
    public function pricing(array $opts): object
    {
        $country = isset($opts['country']) ? (string) $opts['country'] : '';
        $service = isset($opts['service']) ? (string) $opts['service'] : '';
        if ($country === '') {
            throw new InvalidArgumentException('country is required');
        }
        if ($service === '') {
            throw new InvalidArgumentException('service is required');
        }

        $mode = (string) ($opts['mode'] ?? 'activation');
        $query = [
            'mode' => $mode,
            'country' => strtolower($country),
            'product' => $service,
        ];
        if (isset($opts['currency']) && is_string($opts['currency']) && $opts['currency'] !== '') {
            $query['currency'] = strtoupper($opts['currency']);
        }
        if (isset($opts['duration_minutes'])) {
            $query['duration'] = (int) $opts['duration_minutes'];
        }

        $res = $this->http->request('GET', '/api/v1/numbers/pricing', $query);
        $d = self::unwrap($res);

        $servicesRaw = isset($d['services']) && is_array($d['services']) ? $d['services'] : [];
        $services = array_map([self::class, 'mapServiceEntry'], $servicesRaw);

        return (object) [
            'mode' => isset($d['mode']) && is_string($d['mode']) ? $d['mode'] : $mode,
            'country' => isset($d['country']) && is_string($d['country']) ? $d['country'] : strtolower($country),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : ($query['currency'] ?? null),
            'service' => $service,
            'services' => array_values($services),
        ];
    }

    // ------------------------------------------------------- internals --

    /**
     * @param  array<string,mixed>  $d
     */
    private static function mapOrder(array $d): object
    {
        return (object) [
            'orderId' => (string) ($d['order_id'] ?? ''),
            'status' => (string) ($d['status'] ?? 'pending'),
            'phone' => isset($d['phone']) && is_string($d['phone']) ? $d['phone'] : null,
            'country' => isset($d['country']) && is_string($d['country']) ? $d['country'] : null,
            'service' => isset($d['service']) && is_string($d['service']) ? $d['service'] : null,
            'mode' => isset($d['mode']) && is_string($d['mode']) ? $d['mode'] : null,
            'priceCents' => isset($d['price_cents']) && is_int($d['price_cents']) ? $d['price_cents'] : null,
            'autoRenew' => isset($d['auto_renew']) && is_bool($d['auto_renew']) ? $d['auto_renew'] : null,
            'expiresAt' => isset($d['expires_at']) && is_string($d['expires_at']) ? $d['expires_at'] : null,
            'createdAt' => isset($d['created_at']) && is_string($d['created_at']) ? $d['created_at'] : null,
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapSms(mixed $m): object
    {
        $r = is_array($m) ? $m : [];

        return (object) [
            'id' => isset($r['id']) && is_int($r['id']) ? $r['id'] : 0,
            'text' => isset($r['text']) && is_string($r['text']) ? $r['text'] : '',
            'sender' => isset($r['sender']) && is_string($r['sender']) ? $r['sender'] : null,
            'receivedAt' => isset($r['received_at']) && is_string($r['received_at']) ? $r['received_at'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $entry
     */
    private static function mapServiceEntry(mixed $entry): object
    {
        $r = is_array($entry) ? $entry : [];
        $durations = isset($r['durations']) && is_array($r['durations']) ? $r['durations'] : [];

        return (object) [
            'name' => isset($r['name']) && is_string($r['name']) ? $r['name'] : '',
            'durations' => array_values(array_map([self::class, 'mapDuration'], $durations)),
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $d
     */
    private static function mapDuration(mixed $d): object
    {
        $r = is_array($d) ? $d : [];
        $available = null;
        if (isset($r['available']) && is_bool($r['available'])) {
            $available = $r['available'];
        } elseif (isset($r['in_stock']) && is_bool($r['in_stock'])) {
            $available = $r['in_stock'];
        }

        return (object) [
            'durationMinutes' => isset($r['duration_minutes']) && is_int($r['duration_minutes']) ? $r['duration_minutes'] : 0,
            'priceCents' => isset($r['price_cents']) && is_int($r['price_cents']) ? $r['price_cents'] : null,
            'price' => isset($r['price']) && (is_int($r['price']) || is_float($r['price'])) ? (float) $r['price'] : null,
            'currency' => isset($r['currency']) && is_string($r['currency']) ? $r['currency'] : null,
            'available' => $available,
            'raw' => $r,
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
}
