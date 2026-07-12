<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Wallet namespace. Hits ``/api/v1/wallet``.
 */
final class Wallet
{
    public function __construct(private readonly Client $http) {}

    /**
     * Snapshot of total / held / available balance.
     *
     * Returns object with ``balance``, ``heldBalance``, ``availableBalance``
     * (all integers, in minor units / cents) and ``currency`` (ISO-4217).
     */
    public function balance(): object
    {
        $res = $this->http->request('GET', '/api/v1/wallet');
        $d = self::unwrap($res);

        return (object) [
            'balance' => self::intOr($d['balance'] ?? null, 0),
            'heldBalance' => self::intOr($d['held_balance'] ?? null, 0),
            'availableBalance' => self::intOr($d['available_balance'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
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
