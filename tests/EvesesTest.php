<?php

declare(strict_types=1);

namespace Eveses\Sdk\Tests;

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;
use Eveses\Sdk\Exceptions\EvesesRateLimitException;
use Eveses\Sdk\Modules\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Eveses PHP SDK.
 *
 * No Guzzle / no Mockery: we inject a tiny callable transport that records
 * the last request and returns canned responses. Same shape as the
 * JS / Python "fake fetch" / "fake session" helpers.
 */
final class EvesesTest extends TestCase
{
    /**
     * Build a callable transport that records every request into the
     * returned ``ArrayObject`` (so callers see the side-effects after a
     * destructuring assignment) and replies from the canned response queue.
     *
     * @param  list<array{status:int, body?:mixed, headers?:array<string,string>}>  $responses
     * @return array{0: callable, 1: \ArrayObject<int,array<string,mixed>>}
     */
    private function fakeTransport(array $responses): array
    {
        /** @var \ArrayObject<int,array<string,mixed>> $calls */
        $calls = new \ArrayObject;
        $queue = $responses;
        $transport = function (array $req) use ($calls, &$queue): array {
            $calls->append($req);
            if ($queue === []) {
                throw new \RuntimeException('fake transport: no more responses queued');
            }
            $next = array_shift($queue);
            $body = '';
            if (array_key_exists('body', $next) && $next['body'] !== null) {
                $body = is_string($next['body']) ? $next['body'] : (string) json_encode($next['body']);
            }
            $headers = array_merge(
                ['Content-Type' => 'application/json'],
                $next['headers'] ?? [],
            );

            return [
                'status' => (int) $next['status'],
                'headers' => $headers,
                'body' => $body,
            ];
        };

        return [$transport, $calls];
    }

    // ----------------------------------------------------------- catalog --

    public function test_catalog_countries_hits_v1_with_mode(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => ['mode' => 'activation', 'countries' => ['ua', 'pl']]]],
        ]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://api.example.test',
            'transport' => $transport,
        ]);

        $res = $client->catalog->countries(['mode' => 'activation']);

        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame(
            'https://api.example.test/api/v1/numbers/countries?mode=activation',
            $calls[0]['url'],
        );
        $this->assertSame('Bearer k', $calls[0]['headers']['Authorization']);
        $this->assertSame('activation', $res->mode);
        $this->assertSame(['ua', 'pl'], $res->countries);
    }

    public function test_catalog_services_uses_products_endpoint(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => ['mode' => 'activation', 'products' => ['telegram', 'wa']]]],
        ]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://api.example.test',
            'transport' => $transport,
        ]);

        $res = $client->catalog->services(['mode' => 'activation', 'country' => 'UA', 'currency' => 'usd']);

        $this->assertSame(
            'https://api.example.test/api/v1/numbers/products?mode=activation',
            $calls[0]['url'],
        );
        $this->assertSame(['telegram', 'wa'], $res->services);
        $this->assertSame('ua', $res->country);
        $this->assertSame('USD', $res->currency);
    }

    public function test_catalog_pricing_maps_services_and_forwards_params(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            [
                'status' => 200,
                'body' => [
                    'data' => [
                        'mode' => 'activation',
                        'country' => 'ua',
                        'currency' => 'USD',
                        'services' => [
                            [
                                'name' => 'telegram',
                                'durations' => [
                                    [
                                        'duration_minutes' => 0,
                                        'price_cents' => 50,
                                        'price' => 0.5,
                                        'currency' => 'USD',
                                        'in_stock' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://api.example.test',
            'transport' => $transport,
        ]);

        $res = $client->catalog->pricing([
            'mode' => 'activation',
            'country' => 'UA',
            'service' => 'telegram',
            'currency' => 'usd',
        ]);

        $url = parse_url($calls[0]['url']);
        parse_str($url['query'] ?? '', $qs);
        $this->assertSame('/api/v1/numbers/pricing', $url['path']);
        $this->assertSame('activation', $qs['mode']);
        $this->assertSame('ua', $qs['country']);
        $this->assertSame('telegram', $qs['product']);
        $this->assertSame('USD', $qs['currency']);

        $this->assertCount(1, $res->services);
        $this->assertSame('telegram', $res->services[0]->name);
        $this->assertSame(50, $res->services[0]->durations[0]->priceCents);
        $this->assertSame(true, $res->services[0]->durations[0]->available);
        $this->assertSame('USD', $res->currency);
    }

    public function test_catalog_pricing_requires_country_and_service(): void
    {
        [$transport] = $this->fakeTransport([]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://x.test',
            'transport' => $transport,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $client->catalog->pricing(['country' => '', 'service' => 'telegram']);
    }

    // ---------------------------------------------------------- webhooks --

    public function test_webhook_verify_accepts_valid_signature(): void
    {
        $secret = 'whsec_test';
        $body = json_encode(['event' => 'order.sms_received', 'data' => ['order_id' => 'X']]);
        $ts = (string) time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);

        $this->assertTrue(Webhooks::verify($body, $sig, $ts, $secret));
        $this->assertTrue(Eveses::verifyWebhook($body, $sig, $ts, $secret));
    }

    public function test_webhook_verify_rejects_wrong_secret_and_tampered_body(): void
    {
        $secret = 'whsec_test';
        $body = '{"a":1}';
        $ts = (string) time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);

        $this->assertFalse(Webhooks::verify($body, $sig, $ts, 'wrong-secret'));
        $this->assertFalse(Webhooks::verify($body.'tamper', $sig, $ts, $secret));
        $this->assertFalse(Webhooks::verify($body, null, $ts, $secret));
        $this->assertFalse(Webhooks::verify($body, $sig, null, $secret));
    }

    public function test_webhook_verify_rejects_stale_timestamp(): void
    {
        $secret = 'whsec_test';
        $body = '{}';
        $ts = (string) (time() - 10_000);
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);

        $this->assertFalse(Webhooks::verify($body, $sig, $ts, $secret));
        // Tolerance disabled -> passes.
        $this->assertTrue(Webhooks::verify($body, $sig, $ts, $secret, 0));
    }

    // ---------------------------------------------------------- proxies --

    public function test_proxies_list_maps_residential_subscription_and_orders(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'residential' => [
                    'host' => 'proxy.eveses.com',
                    'ports' => ['http' => 12321, 'socks5' => 32325],
                    'username' => 'u', 'password' => 'p',
                    'example' => 'proxy.eveses.com:12321:u:p',
                    'curl' => 'curl -x ...',
                    'traffic_gb_available' => 4.5, 'traffic_gb_used' => 0.5,
                ],
                'subscription' => [
                    'status' => 'active', 'gb' => 5.0, 'discount_pct' => 10,
                    'next_renews_at' => '2026-07-01T00:00:00+00:00', 'renew_failures' => 0,
                ],
                'orders' => [
                    ['uuid' => 'o1', 'type' => 'isp', 'kind' => 'static', 'gb' => null,
                        'quantity' => 2, 'location' => 'US', 'status' => 'active',
                        'price_cents' => 1200, 'currency' => 'USD', 'proxies' => [],
                        'auto_extend' => true, 'extendable' => true,
                        'expires_at' => '2026-08-01T00:00:00+00:00', 'created_at' => '2026-07-01T00:00:00+00:00'],
                ],
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->proxies->list();

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/proxies', $calls[0]['url']);
        $this->assertSame(4.5, $res->residential->trafficGbAvailable);
        $this->assertSame('active', $res->subscription->status);
        $this->assertSame(10, $res->subscription->discountPct);
        $this->assertCount(1, $res->orders);
        $this->assertSame('o1', $res->orders[0]->uuid);
        $this->assertSame(1200, $res->orders[0]->priceCents);
        $this->assertTrue($res->orders[0]->autoExtend);
    }

    public function test_proxies_quote_residential_forwards_query(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'type' => 'residential', 'gb' => 5, 'price_cents' => 500,
                'currency' => 'USD', 'per_gb_cents' => 100, 'discount_pct' => 0,
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->proxies->quote(['type' => 'residential', 'gb' => 5, 'subscription' => true]);

        $url = parse_url($calls[0]['url']);
        parse_str($url['query'] ?? '', $qs);
        $this->assertSame('/api/account/proxies/quote', $url['path']);
        $this->assertSame('residential', $qs['type']);
        $this->assertSame('5', $qs['gb']);
        $this->assertSame('true', $qs['subscription']);
        $this->assertSame(500, $res->data['price_cents']);
    }

    public function test_proxies_purchase_sends_idempotency_header_and_body(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['data' => [
                'uuid' => 'o9', 'type' => 'isp', 'kind' => 'static', 'quantity' => 1,
                'status' => 'active', 'price_cents' => 600, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $order = $client->proxies->purchase([
            'type' => 'isp', 'product_id' => 1, 'plan_id' => 2, 'location_id' => 3,
            'quantity' => 1, 'idempotency_key' => 'idem-123',
        ]);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/proxies/purchase', $calls[0]['url']);
        $this->assertSame('idem-123', $calls[0]['headers']['Idempotency-Key']);
        $sent = json_decode($calls[0]['body'], true);
        $this->assertSame('isp', $sent['type']);
        $this->assertSame(3, $sent['location_id']);
        $this->assertSame('o9', $order->uuid);
        $this->assertSame(600, $order->priceCents);
    }

    public function test_proxies_auto_renew_posts_enabled_flag(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'uuid' => 'o1', 'type' => 'isp', 'kind' => 'static', 'status' => 'active',
                'auto_extend' => true, 'price_cents' => 0, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $order = $client->proxies->autoRenew('o1', true);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/proxies/o1/auto-renew', $calls[0]['url']);
        $this->assertSame(['enabled' => true], json_decode($calls[0]['body'], true));
        $this->assertTrue($order->autoExtend);
    }

    public function test_proxies_reset_sessions_posts_to_sessions_reset(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['reset' => true]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $client->proxies->resetSessions();

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/proxies/sessions/reset', $calls[0]['url']);
    }

    // ------------------------------------------------------ web-unblocker --

    public function test_web_unblocker_list_maps_access_and_orders(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'access' => [
                    'host' => 'unblock.eveses.com', 'port' => 12323,
                    'username' => 'u', 'password' => 'p',
                    'requests_purchased' => 10000, 'requests_used' => 100, 'requests_remaining' => 9900,
                ],
                'subscription' => null,
                'orders' => [
                    ['uuid' => 'w1', 'product' => 'web_unblocker', 'requests' => 10000,
                        'status' => 'active', 'price_cents' => 500, 'currency' => 'USD',
                        'created_at' => '2026-07-01T00:00:00+00:00'],
                ],
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->webUnblocker->list();

        $this->assertSame('https://x.test/api/account/web-unblocker', $calls[0]['url']);
        $this->assertSame(9900, $res->access->requestsRemaining);
        $this->assertNull($res->subscription);
        $this->assertSame('w1', $res->orders[0]->uuid);
    }

    public function test_web_unblocker_quote_forwards_requests(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'product' => 'web_unblocker', 'requests' => 10000, 'unit' => 'request',
                'price_cents' => 500, 'per_1k_cents' => 50, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->webUnblocker->quote(10000);

        $url = parse_url($calls[0]['url']);
        parse_str($url['query'] ?? '', $qs);
        $this->assertSame('/api/account/web-unblocker/quote', $url['path']);
        $this->assertSame('10000', $qs['requests']);
        $this->assertSame(500, $res->priceCents);
        $this->assertSame(50, $res->per1kCents);
    }

    public function test_web_unblocker_purchase_sends_idempotency_and_subscription(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['data' => [
                'uuid' => 'w9', 'product' => 'web_unblocker', 'requests' => 5000,
                'status' => 'active', 'price_cents' => 300, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $order = $client->webUnblocker->purchase([
            'requests' => 5000, 'subscription' => true, 'idempotency_key' => 'wu-idem',
        ]);

        $this->assertSame('https://x.test/api/account/web-unblocker/purchase', $calls[0]['url']);
        $this->assertSame('wu-idem', $calls[0]['headers']['Idempotency-Key']);
        $this->assertSame(['requests' => 5000, 'subscription' => true], json_decode($calls[0]['body'], true));
        $this->assertSame('w9', $order->uuid);
    }

    public function test_web_unblocker_cancel_subscription_posts_action(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => ['status' => 'cancelled', 'requests' => 0]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $sub = $client->webUnblocker->cancelSubscription();

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/web-unblocker/subscription/cancel', $calls[0]['url']);
        $this->assertSame('cancelled', $sub->status);
    }

    // ----------------------------------------------------------- emails --

    public function test_emails_list_maps_addresses(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => ['emails' => [
                ['uuid' => 'e1', 'address' => 'a@b.com', 'domain' => 'b.com', 'site' => null,
                    'status' => 'active', 'price_cents' => 100, 'currency' => 'USD',
                    'message_count' => 0, 'expires_at' => null, 'created_at' => '2026-07-01T00:00:00+00:00'],
            ]]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->emails->list();

        $this->assertSame('https://x.test/api/account/emails', $calls[0]['url']);
        $this->assertCount(1, $res->emails);
        $this->assertSame('a@b.com', $res->emails[0]->address);
        $this->assertSame(100, $res->emails[0]->priceCents);
    }

    public function test_emails_quote_forwards_domain_and_site(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'domain' => 'b.com', 'provider' => 'catchall', 'price_cents' => 100, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->emails->quote(['domain' => 'b.com', 'site' => 'example.com']);

        $url = parse_url($calls[0]['url']);
        parse_str($url['query'] ?? '', $qs);
        $this->assertSame('/api/account/emails/quote', $url['path']);
        $this->assertSame('b.com', $qs['domain']);
        $this->assertSame('example.com', $qs['site']);
        $this->assertSame(100, $res->priceCents);
    }

    public function test_emails_purchase_sends_idempotency_header(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['data' => [
                'uuid' => 'e9', 'address' => 'x@b.com', 'domain' => 'b.com',
                'status' => 'active', 'price_cents' => 100, 'currency' => 'USD',
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $order = $client->emails->purchase(['domain' => 'b.com', 'idempotency_key' => 'em-idem']);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/emails/purchase', $calls[0]['url']);
        $this->assertSame('em-idem', $calls[0]['headers']['Idempotency-Key']);
        $this->assertSame(['domain' => 'b.com'], json_decode($calls[0]['body'], true));
        $this->assertSame('e9', $order->uuid);
    }

    public function test_emails_get_returns_messages(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [
                'uuid' => 'e1', 'address' => 'a@b.com', 'domain' => 'b.com', 'status' => 'received',
                'price_cents' => 100, 'currency' => 'USD', 'message_count' => 1,
                'messages' => [
                    ['from' => 's@x.com', 'subject' => 'Hi', 'body' => 'Body', 'received_at' => '2026-07-01T00:00:00+00:00'],
                ],
            ]]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->emails->get('e1');

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/emails/e1', $calls[0]['url']);
        $this->assertCount(1, $res->messages);
        $this->assertSame('Hi', $res->messages[0]->subject);
        $this->assertSame('s@x.com', $res->messages[0]->from);
    }

    // ------------------------------------------------------- exceptions --

    public function test_exception_hierarchy(): void
    {
        $this->assertInstanceOf(EvesesException::class, new EvesesAuthException);
        $this->assertInstanceOf(EvesesException::class, new EvesesRateLimitException);
        $this->assertInstanceOf(\RuntimeException::class, new EvesesException('x', 0));
    }

    public function test_non_ok_response_maps_to_typed_exceptions(): void
    {
        [$transport] = $this->fakeTransport([
            ['status' => 401, 'body' => ['message' => 'Unauthenticated.']],
        ]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://x.test',
            'transport' => $transport,
        ]);

        try {
            $client->wallet->balance();
            $this->fail('expected EvesesAuthException');
        } catch (EvesesAuthException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('Unauthenticated.', $e->getMessage());
        }
    }
}
