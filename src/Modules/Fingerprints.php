<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Fingerprints namespace. Resells 2captcha's Fingerprint API, billed
 * pay-per-use from the wallet (count-on-success). Unlike captcha-solving this
 * is synchronous: one request returns a complete fingerprint. Hits
 * ``/api/account/fingerprints/*``.
 */
final class Fingerprints
{
    public function __construct(private readonly Client $http) {}

    /**
     * Generate a browser fingerprint from the given filter params.
     *
     * @param  array<string,mixed>  $params
     * @return object{fingerprint: array<string,mixed>, priceMicroUsd: int|null}
     */
    public function generate(array $params = []): object
    {
        return $this->request('/api/account/fingerprints/generate', $params);
    }

    /**
     * Fetch a random fingerprint, optionally narrowed by filter params.
     *
     * @param  array<string,mixed>  $params
     * @return object{fingerprint: array<string,mixed>, priceMicroUsd: int|null}
     */
    public function random(array $params = []): object
    {
        return $this->request('/api/account/fingerprints/random', $params);
    }

    /** @param array<string,mixed> $params */
    private function request(string $path, array $params): object
    {
        $res = (array) $this->http->request('POST', $path, null, $params);

        return (object) [
            'fingerprint' => isset($res['fingerprint']) && is_array($res['fingerprint']) ? $res['fingerprint'] : [],
            'priceMicroUsd' => isset($res['price_micro_usd']) ? (int) $res['price_micro_usd'] : null,
        ];
    }
}
