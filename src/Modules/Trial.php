<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Trial namespace — check and activate free-trial access for one or more
 * product services. Hits ``/api/v1/trial/*``.
 */
final class Trial
{
    public function __construct(private readonly Client $http) {}

    /**
     * Current trial status for the account (eligibility, active services, etc.).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return (array) $this->http->request('GET', '/api/v1/trial');
    }

    /**
     * Subscribe to free-trial access for the given service slugs.
     *
     * @param  string[]  $services  Service slugs to enrol in the trial.
     * @return array<string,mixed>
     */
    public function subscribe(array $services): array
    {
        return (array) $this->http->request('POST', '/api/v1/trial/subscribe', null, ['services' => $services]);
    }
}
