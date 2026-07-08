<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Http\Client;
use InvalidArgumentException;

/**
 * Emails namespace. Hits ``/api/account/emails``.
 *
 * Rent an inbox address (our own catch-all domains, or a reseller) and read its
 * mail:
 *   - GET    /                overview (rented addresses)
 *   - GET    /domains?site=   rentable domains (reseller providers need ``site``)
 *   - GET    /quote           price a concrete pick
 *   - POST   /purchase        rent an address (idempotent)
 *   - GET    /{uuid}          one address + its received messages (also the
 *                             reseller inbox-refresh mechanism — poll this)
 *   - GET    /{uuid}/messages a paginated page of the address's messages
 *   - POST   /{uuid}/messages/{id}/read  mark one message read
 *   - DELETE /{uuid}          release the address (soft cancel, no refund)
 *
 * Money is integer cents; timestamps are ISO-8601 strings.
 */
final class Emails
{
    public function __construct(private readonly Client $http) {}

    /**
     * The user's rented addresses. Pass ``$includeReleased = true`` to also
     * return released (soft-cancelled) addresses (sends ``?include_released=1``).
     *
     * @return object{emails:array<int,object>, raw:array<string,mixed>}
     */
    public function list(bool $includeReleased = false): object
    {
        $query = $includeReleased ? ['include_released' => 1] : [];
        $res = $this->http->request('GET', '/api/account/emails', $query);
        $d = self::unwrap($res);

        return (object) [
            'emails' => array_map(
                [self::class, 'mapAddress'],
                array_values((array) ($d['emails'] ?? [])),
            ),
            'raw' => $d,
        ];
    }

    /**
     * Rentable domains (our price). Reseller providers need ``site``; our own
     * catch-all domains ignore it.
     *
     * @param  array{site?: string}  $opts
     * @return object{domains:array<int,object>, currency:string}
     */
    public function domains(array $opts = []): object
    {
        $query = [];
        if (isset($opts['site']) && $opts['site'] !== '') {
            $query['site'] = (string) $opts['site'];
        }

        $res = $this->http->request('GET', '/api/account/emails/domains', $query);
        $d = self::unwrap($res);

        return (object) [
            'domains' => array_map(
                [self::class, 'mapDomain'],
                array_values((array) ($d['domains'] ?? [])),
            ),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
        ];
    }

    /**
     * Price a concrete pick.
     *
     * @param array{domain: string, site?: string, provider?: string} $opts
     * @return object{domain:?string, provider:?string, priceCents:int, currency:string, raw:array<string,mixed>}
     */
    public function quote(array $opts): object
    {
        $domain = isset($opts['domain']) ? (string) $opts['domain'] : '';
        if ($domain === '') {
            throw new InvalidArgumentException('domain is required');
        }

        $query = ['domain' => $domain];
        if (isset($opts['site']) && $opts['site'] !== '') {
            $query['site'] = (string) $opts['site'];
        }
        if (isset($opts['provider']) && $opts['provider'] !== '') {
            $query['provider'] = (string) $opts['provider'];
        }

        $res = $this->http->request('GET', '/api/account/emails/quote', $query);
        $d = self::unwrap($res);

        return (object) [
            'domain' => isset($d['domain']) && is_string($d['domain']) ? $d['domain'] : null,
            'provider' => isset($d['provider']) && is_string($d['provider']) ? $d['provider'] : null,
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
            'raw' => $d,
        ];
    }

    /**
     * Rent an address. Pass ``idempotency_key`` to make the purchase safely
     * retryable — it is sent as the ``Idempotency-Key`` header.
     *
     * @param array{domain: string, site?: string, provider?: string, idempotency_key?: string} $req
     */
    public function purchase(array $req): object
    {
        $domain = isset($req['domain']) ? (string) $req['domain'] : '';
        if ($domain === '') {
            throw new InvalidArgumentException('domain is required');
        }

        $body = ['domain' => $domain];
        if (isset($req['site']) && $req['site'] !== '') {
            $body['site'] = (string) $req['site'];
        }
        if (isset($req['provider']) && $req['provider'] !== '') {
            $body['provider'] = (string) $req['provider'];
        }

        $headers = [];
        if (isset($req['idempotency_key']) && $req['idempotency_key'] !== '') {
            $headers['Idempotency-Key'] = (string) $req['idempotency_key'];
        }

        $res = $this->http->request('POST', '/api/account/emails/purchase', body: $body, headers: $headers);

        return self::mapAddress(self::unwrap($res));
    }

    /**
     * One address + its received messages. This call also live-syncs reseller
     * inboxes from the upstream provider, so it doubles as the inbox-refresh
     * mechanism — poll it to pick up new mail.
     */
    public function get(string $uuid): object
    {
        $res = $this->http->request('GET', '/api/account/emails/'.rawurlencode($uuid));

        return self::mapAddress(self::unwrap($res));
    }

    /**
     * A paginated page of an address's received messages. Unlike ``get()`` this
     * returns full message rows (id, read state) plus pagination metadata.
     *
     * @return object{messages:array<int,object>, page:int, perPage:int, total:int, hasMore:bool, raw:array<string,mixed>}
     */
    public function messages(string $uuid, int $page = 1, int $perPage = 20): object
    {
        $res = $this->http->request(
            'GET',
            '/api/account/emails/'.rawurlencode($uuid).'/messages',
            ['page' => $page, 'per_page' => $perPage],
        );
        $d = self::unwrap($res);

        return (object) [
            'messages' => array_map(
                [self::class, 'mapMessage'],
                array_values((array) ($d['messages'] ?? [])),
            ),
            'page' => self::intOr($d['page'] ?? null, $page),
            'perPage' => self::intOr($d['per_page'] ?? null, $perPage),
            'total' => self::intOr($d['total'] ?? null, 0),
            'hasMore' => isset($d['has_more']) && is_bool($d['has_more']) ? $d['has_more'] : false,
            'raw' => $d,
        ];
    }

    /**
     * Mark a single message as read. Returns the message id and its read state.
     *
     * @return object{id:?string, read:bool, raw:array<string,mixed>}
     */
    public function markRead(string $uuid, string $messageId): object
    {
        $res = $this->http->request(
            'POST',
            '/api/account/emails/'.rawurlencode($uuid).'/messages/'.rawurlencode($messageId).'/read',
        );
        $d = self::unwrap($res);

        return (object) [
            'id' => isset($d['id']) && (is_string($d['id']) || is_int($d['id'])) ? (string) $d['id'] : null,
            'read' => isset($d['read']) && is_bool($d['read']) ? $d['read'] : false,
            'raw' => $d,
        ];
    }

    /** Release an address (stop receiving). Soft cancel — no refund. */
    public function delete(string $uuid): object
    {
        $res = $this->http->request('DELETE', '/api/account/emails/'.rawurlencode($uuid));

        return self::mapAddress(self::unwrap($res));
    }

    // ── mappers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapAddress(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'uuid' => isset($d['uuid']) && is_string($d['uuid']) ? $d['uuid'] : '',
            'address' => isset($d['address']) && is_string($d['address']) ? $d['address'] : '',
            'domain' => isset($d['domain']) && is_string($d['domain']) ? $d['domain'] : null,
            'site' => isset($d['site']) && is_string($d['site']) ? $d['site'] : null,
            'status' => isset($d['status']) && is_string($d['status']) ? $d['status'] : 'pending',
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'currency' => isset($d['currency']) && is_string($d['currency']) ? $d['currency'] : 'USD',
            'messageCount' => self::intOr($d['message_count'] ?? null, 0),
            'messages' => array_key_exists('messages', $d)
                ? array_map([self::class, 'mapMessage'], (array) $d['messages'])
                : null,
            'expiresAt' => isset($d['expires_at']) && is_string($d['expires_at']) ? $d['expires_at'] : null,
            'createdAt' => isset($d['created_at']) && is_string($d['created_at']) ? $d['created_at'] : null,
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapDomain(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'provider' => isset($d['provider']) && is_string($d['provider']) ? $d['provider'] : null,
            'domain' => isset($d['domain']) && is_string($d['domain']) ? $d['domain'] : '',
            'priceCents' => self::intOr($d['price_cents'] ?? null, 0),
            'available' => isset($d['available']) && is_bool($d['available']) ? $d['available'] : true,
            'raw' => $d,
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $m
     */
    private static function mapMessage(mixed $m): object
    {
        $d = is_array($m) ? $m : [];

        return (object) [
            'id' => isset($d['id']) && (is_string($d['id']) || is_int($d['id'])) ? (string) $d['id'] : null,
            'from' => isset($d['from']) && is_string($d['from']) ? $d['from'] : null,
            'subject' => isset($d['subject']) && is_string($d['subject']) ? $d['subject'] : null,
            'body' => isset($d['body']) && is_string($d['body']) ? $d['body'] : null,
            'receivedAt' => isset($d['received_at']) && is_string($d['received_at']) ? $d['received_at'] : null,
            'readAt' => isset($d['read_at']) && is_string($d['read_at']) ? $d['read_at'] : null,
            'isRead' => isset($d['is_read']) && is_bool($d['is_read']) ? $d['is_read'] : false,
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

    private static function intOr(mixed $value, int $fallback): int
    {
        return is_int($value) ? $value : $fallback;
    }
}
