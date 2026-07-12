<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Quotas namespace — remaining prepaid balances (only products with a
 * decrementing counter appear). Hits ``GET /api/v1/quotas``.
 *
 * A key is omitted when the user has none. Numbers/emails/captcha never
 * have quotas.
 */
final class Quotas
{
    public function __construct(private readonly Client $http) {}

    /**
     * Remaining prepaid balances (``trial``, ``proxy``, ``webunblocker``).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return (array) $this->http->request('GET', '/api/v1/quotas');
    }
}
