<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;

/**
 * Emails namespace — buy and manage temporary/disposable e-mail addresses.
 * Hits ``/api/account/emails/*``.
 */
final class Emails
{
    public function __construct(private readonly Client $http) {}

    /**
     * Available e-mail domains, optionally filtered by site.
     *
     * @return array<string,mixed>
     */
    public function domains(?string $site = null): array
    {
        $query = [];
        if ($site !== null && $site !== '') {
            $query['site'] = $site;
        }

        return (array) $this->http->request('GET', '/api/account/emails/domains', $query ?: null);
    }

    /**
     * Estimate a purchase: price for a specific domain/site/provider combination.
     *
     * @return array<string,mixed>
     */
    public function quote(string $domain, ?string $site = null, ?string $provider = null): array
    {
        $query = ['domain' => $domain];
        if ($site !== null && $site !== '') {
            $query['site'] = $site;
        }
        if ($provider !== null && $provider !== '') {
            $query['provider'] = $provider;
        }

        return (array) $this->http->request('GET', '/api/account/emails/quote', $query);
    }

    /**
     * Purchase a temporary e-mail address. Returns the created mailbox object.
     */
    public function purchase(string $domain, ?string $site = null, ?string $provider = null, ?string $idempotencyKey = null): object
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = ['domain' => $domain];
        if ($site !== null && $site !== '') {
            $body['site'] = $site;
        }
        if ($provider !== null && $provider !== '') {
            $body['provider'] = $provider;
        }

        $res = (array) $this->http->request('POST', '/api/account/emails/purchase', null, $body, $headers);

        return self::mapMailbox($res);
    }

    /**
     * List all mailboxes owned by the account.
     *
     * @return array<int,object>
     */
    public function list(bool $includeReleased = false): array
    {
        $query = [];
        if ($includeReleased) {
            $query['include_released'] = '1';
        }

        $res = (array) $this->http->request('GET', '/api/account/emails', $query ?: null);

        $items = isset($res['data']) && is_array($res['data']) ? $res['data'] : $res;

        return array_values(array_map(
            static fn (mixed $m): object => self::mapMailbox(is_array($m) ? $m : []),
            is_array($items) ? $items : [],
        ));
    }

    /**
     * Retrieve a single mailbox by UUID.
     */
    public function get(string $uuid): object
    {
        $res = (array) $this->http->request('GET', '/api/account/emails/'.rawurlencode($uuid));

        return self::mapMailbox($res);
    }

    /**
     * Paginated list of messages in a mailbox.
     *
     * @return array<string,mixed>
     */
    public function messages(string $uuid, int $page = 1, int $perPage = 20): array
    {
        return (array) $this->http->request(
            'GET',
            '/api/account/emails/'.rawurlencode($uuid).'/messages',
            ['page' => $page, 'per_page' => $perPage],
        );
    }

    /**
     * Mark a specific message as read.
     *
     * @return array<string,mixed>
     */
    public function markRead(string $uuid, string $messageId): array
    {
        return (array) $this->http->request(
            'POST',
            '/api/account/emails/'.rawurlencode($uuid).'/messages/'.rawurlencode($messageId).'/read',
        );
    }

    /**
     * Release (delete) a mailbox — stops accepting mail and frees the address.
     *
     * @return array<string,mixed>
     */
    public function release(string $uuid): array
    {
        return (array) $this->http->request('DELETE', '/api/account/emails/'.rawurlencode($uuid));
    }

    /**
     * @param  array<string,mixed>  $r
     */
    private static function mapMailbox(array $r): object
    {
        return (object) [
            'uuid' => isset($r['uuid']) ? (string) $r['uuid'] : '',
            'address' => isset($r['address']) && is_string($r['address']) ? $r['address'] : null,
            'domain' => isset($r['domain']) && is_string($r['domain']) ? $r['domain'] : null,
            'site' => isset($r['site']) && is_string($r['site']) ? $r['site'] : null,
            'provider' => isset($r['provider']) && is_string($r['provider']) ? $r['provider'] : null,
            'status' => isset($r['status']) ? (string) $r['status'] : '',
            'priceCents' => isset($r['price_cents']) ? (int) $r['price_cents'] : 0,
            'currency' => isset($r['currency']) && is_string($r['currency']) ? $r['currency'] : 'USD',
            'expiresAt' => isset($r['expires_at']) && is_string($r['expires_at']) ? $r['expires_at'] : null,
            'createdAt' => isset($r['created_at']) && is_string($r['created_at']) ? $r['created_at'] : null,
            'raw' => $r,
        ];
    }
}
