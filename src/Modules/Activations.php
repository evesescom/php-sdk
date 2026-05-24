<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Activations / orders namespace.
 *
 * Hits ``/api/account/orders/*`` (the same controllers v1 wraps for
 * API-key consumers). Returns array-of-array shapes converted from the
 * server's snake_case JSON, plus convenient typed-property objects via
 * ``stdClass``-style cast for the most common fields.
 */
final class Activations
{
    public function __construct(private readonly Client $http) {}

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
            path: '/api/account/orders',
            body: $body,
            headers: $headers,
        );

        return self::mapOrder(self::unwrap($res));
    }

    public function get(string $orderId): object
    {
        $res = $this->http->request('GET', '/api/account/orders/'.rawurlencode($orderId));

        return self::mapOrder(self::unwrap($res));
    }

    public function cancel(string $orderId): object
    {
        $res = $this->http->request('POST', '/api/account/orders/'.rawurlencode($orderId).'/cancel');

        return self::mapOrder(self::unwrap($res));
    }

    public function finish(string $orderId): object
    {
        $res = $this->http->request('POST', '/api/account/orders/'.rawurlencode($orderId).'/finish');

        return self::mapOrder(self::unwrap($res));
    }

    /**
     * Get all SMS for an order. Returns object with ``orderId``, ``stored``,
     * ``fresh`` (each an array of objects with ``id``, ``text``, ``sender``,
     * ``receivedAt``).
     */
    public function sms(string $orderId): object
    {
        $res = $this->http->request('GET', '/api/account/orders/'.rawurlencode($orderId).'/sms');
        $data = self::unwrap($res);

        return (object) [
            'orderId' => (string) ($data['order_id'] ?? $orderId),
            'stored' => array_map([self::class, 'mapSms'], (array) ($data['stored'] ?? [])),
            'fresh' => array_map([self::class, 'mapSms'], (array) ($data['fresh'] ?? [])),
        ];
    }

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
