<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;
use InvalidArgumentException;

/**
 * Catalog namespace — read-only metadata used to drive the UX before
 * creating an order.
 *
 * Targets the API-key-authenticated v1 routes:
 *   - GET /api/v1/numbers/countries?mode=
 *   - GET /api/v1/numbers/products?mode=     (the "services" list)
 *   - GET /api/v1/numbers/pricing?mode=&country=&product=&duration=
 *
 * Wire-shape note: the v1 list endpoint is named ``products`` for legacy
 * reasons — it returns the same flat string list the rest of the SDK
 * calls "services". The pricing endpoint takes ``product=`` on the wire,
 * which we accept here under the friendlier ``service`` key.
 */
final class Catalog
{
    public function __construct(private readonly Client $http) {}

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
