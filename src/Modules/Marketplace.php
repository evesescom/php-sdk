<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Marketplace namespace — buy normalized digital goods (e.g. accounts) while
 * the upstream provider stays hidden. Public catalog/discovery lives under
 * ``/api/public/marketplace/*``; ordering under ``/api/v1/marketplace/*``.
 *
 * Attributes are normalized across providers:
 *   - ``country``  ISO-2 uppercase, or a region slug (mix/cis/eu/asia/africa/latam)
 *   - ``origin``   autoreg | selfreg | real | retrieve
 *   - ``format``   tdata | session_json | session
 *   - ``twofa``    bool
 *   - ``group_by`` attributes → groups with ``prices_cents``
 */
final class Marketplace
{
    public function __construct(private readonly Client $http) {}

    /**
     * Browse the normalized catalog. Only provided filters are sent.
     *
     * @param array{
     *     category?: string,
     *     country?: string,
     *     origin?: string,
     *     format?: string,
     *     twofa?: bool,
     *     group_by?: string,
     * } $filters
     * @return array<string,mixed>
     */
    public function catalog(array $filters = []): array
    {
        $query = [];
        foreach (['category', 'country', 'origin', 'format', 'group_by'] as $key) {
            if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        if (isset($filters['twofa'])) {
            $query['twofa'] = $filters['twofa'] ? 'true' : 'false';
        }

        return (array) $this->http->request('GET', '/api/public/marketplace/catalog', $query ?: null);
    }

    /**
     * List the marketplace categories.
     *
     * @return array<string,mixed>
     */
    public function categories(): array
    {
        return (array) $this->http->request('GET', '/api/public/marketplace/categories');
    }

    /**
     * Available filter facets, optionally scoped to a category.
     *
     * @return array<string,mixed>
     */
    public function filters(?string $category = null): array
    {
        $query = $category !== null ? ['category' => $category] : null;

        return (array) $this->http->request('GET', '/api/public/marketplace/filters', $query);
    }

    /**
     * Price a category/SKU before buying.
     *
     * @return array<string,mixed>
     */
    public function quote(string $category, string $sku): array
    {
        return (array) $this->http->request('POST', '/api/v1/marketplace/quote', null, [
            'category' => $category,
            'sku' => $sku,
        ]);
    }

    /**
     * Buy from the marketplace. Returns the order object.
     *
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    public function buy(string $category, string $sku, int $quantity = 1, array $inputs = [], ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = [
            'category' => $category,
            'sku' => $sku,
            'quantity' => $quantity,
            'inputs' => $inputs,
        ];

        return (array) $this->http->request('POST', '/api/v1/marketplace/buy', null, $body, $headers ?: null);
    }

    /**
     * List the account's marketplace orders.
     *
     * @return array<string,mixed>
     */
    public function orders(): array
    {
        return (array) $this->http->request('GET', '/api/v1/marketplace/orders');
    }

    /**
     * Show a single marketplace order by UUID.
     *
     * @return array<string,mixed>
     */
    public function order(string $uuid): array
    {
        return (array) $this->http->request('GET', '/api/v1/marketplace/orders/'.rawurlencode($uuid));
    }

    /**
     * Reveal the delivered goods for a marketplace order.
     *
     * @return array<string,mixed>
     */
    public function reveal(string $uuid): array
    {
        return (array) $this->http->request('POST', '/api/v1/marketplace/orders/'.rawurlencode($uuid).'/reveal');
    }
}
