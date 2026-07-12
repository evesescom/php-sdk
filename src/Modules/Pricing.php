<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Pricing namespace — every product's prices in one call. Hits
 * ``GET /api/v1/pricing``.
 *
 * The response carries a top-level ``currency`` plus per-service keys
 * (``numbers``, ``proxy``, ``webunblocker``, ``emails``, ``captcha``).
 */
final class Pricing
{
    public function __construct(private readonly Client $http) {}

    /**
     * All prices, in one request.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return (array) $this->http->request('GET', '/api/v1/pricing');
    }
}
