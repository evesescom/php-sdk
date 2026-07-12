<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Account namespace — the authenticated caller's own profile. Hits
 * ``GET /api/v1/me``.
 *
 * As of v1 the ``me`` payload also carries ``abilities`` (what THIS token
 * can do) and ``features`` (which products are enabled for the account), so
 * callers can gate product entry points instead of hardcoding flags.
 */
final class Account
{
    public function __construct(private readonly Client $http) {}

    /**
     * The current account. Returns an object with the raw ``me`` payload plus
     * convenience ``abilities`` (list of strings) and ``features`` (map of
     * product => bool) accessors.
     */
    public function me(): object
    {
        $res = $this->http->request('GET', '/api/v1/me');
        $d = self::unwrap($res);

        $abilities = isset($d['abilities']) && is_array($d['abilities'])
            ? array_values(array_map('strval', $d['abilities']))
            : [];
        $features = isset($d['features']) && is_array($d['features']) ? $d['features'] : [];

        return (object) [
            'abilities' => $abilities,
            'features' => (object) $features,
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
}
