<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Orders namespace — the global, cross-product order history. Returns the
 * normalized ``OrderView`` shape for every product (numbers, proxy,
 * webunblocker, emails). Captcha is NOT here (it's usage).
 * Hits ``/api/v1/orders``.
 */
final class Orders
{
    public function __construct(private readonly Client $http) {}

    /**
     * Cursor-paginated cross-product order history (newest first).
     *
     * @param array{
     *     service?: string,
     *     status?: string,
     *     cursor?: string,
     *     limit?: int,
     * } $opts
     * @return array<string,mixed> ``{ data: OrderView[], meta: { next_cursor, has_more } }``
     */
    public function list(array $opts = []): array
    {
        $query = [];
        foreach (['service', 'status', 'cursor'] as $k) {
            if (isset($opts[$k]) && is_string($opts[$k]) && $opts[$k] !== '') {
                $query[$k] = $opts[$k];
            }
        }
        if (isset($opts['limit'])) {
            $query['limit'] = (int) $opts['limit'];
        }

        return (array) $this->http->request('GET', '/api/v1/orders', $query ?: null);
    }

    /**
     * The normalized ``OrderView`` for any single order (any product).
     *
     * @return array<string,mixed>
     */
    public function get(string $uuid): array
    {
        return (array) $this->http->request('GET', '/api/v1/orders/'.rawurlencode($uuid));
    }
}
