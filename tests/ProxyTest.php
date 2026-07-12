<?php

declare(strict_types=1);

namespace Eveses\Sdk\Tests;

use Eveses\Sdk\Eveses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the proxy module. Injects a fake transport (no network);
 * asserts URL / method / body / headers on the wire.
 */
final class ProxyTest extends TestCase
{
    /**
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

            return [
                'status' => (int) $next['status'],
                'headers' => array_merge(['Content-Type' => 'application/json'], $next['headers'] ?? []),
                'body' => $body,
            ];
        };

        return [$transport, $calls];
    }

    private function client(callable $transport): Eveses
    {
        return new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);
    }

    public function test_purchase_residential_sends_body_and_idempotency_key(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => [
                'uuid' => 'px_abc', 'type' => 'residential', 'kind' => 'metered', 'gb' => 10,
                'status' => 'active', 'price_cents' => 900, 'auto_extend' => false, 'extendable' => false,
            ]],
        ]);
        $order = $this->client($transport)->proxy->purchase([
            'type' => 'residential', 'gb' => 10, 'subscription' => true, 'idempotency_key' => 'idem-px',
        ]);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/v1/proxy/orders', $calls[0]['url']);
        $this->assertSame('idem-px', $calls[0]['headers']['Idempotency-Key']);
        $sent = json_decode((string) $calls[0]['body'], true);
        $this->assertSame(['type' => 'residential', 'gb' => 10, 'subscription' => true], $sent);

        $this->assertSame('px_abc', $order->uuid);
        $this->assertSame('active', $order->status);
        $this->assertSame(900, $order->priceCents);
        $this->assertSame('USD', $order->currency);
        $this->assertSame(10.0, $order->gb);
    }

    public function test_purchase_static_sends_selection(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['uuid' => 'px_isp', 'type' => 'isp', 'status' => 'active', 'price_cents' => 300]],
        ]);
        $this->client($transport)->proxy->purchase([
            'type' => 'isp', 'product_id' => 9, 'plan_id' => 4, 'location_id' => 51,
            'location_name' => 'Australia', 'quantity' => 3,
        ]);

        $sent = json_decode((string) $calls[0]['body'], true);
        $this->assertSame([
            'type' => 'isp', 'product_id' => 9, 'plan_id' => 4, 'location_id' => 51,
            'location_name' => 'Australia', 'quantity' => 3,
        ], $sent);
    }

    public function test_quote_residential_builds_query(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['price_cents' => 900, 'gb' => 10, 'currency' => 'USD']],
        ]);
        $quote = $this->client($transport)->proxy->quote(['type' => 'residential', 'gb' => 10, 'subscription' => true]);

        $this->assertStringStartsWith('https://x.test/api/v1/proxy/quote?', $calls[0]['url']);
        $this->assertStringContainsString('type=residential', $calls[0]['url']);
        $this->assertStringContainsString('gb=10', $calls[0]['url']);
        $this->assertStringContainsString('subscription=true', $calls[0]['url']);
        $this->assertSame(900, $quote['price_cents']);
    }

    public function test_list_maps_residential_subscription_and_orders(): void
    {
        [$transport] = $this->fakeTransport([
            ['status' => 200, 'body' => [
                'residential' => ['host' => 'proxy.eveses.com', 'username' => 'u', 'password' => 'p', 'traffic_gb_available' => 5, 'traffic_gb_used' => 1],
                'subscription' => ['status' => 'active', 'gb' => 10, 'discount_pct' => 15, 'renew_failures' => 0],
                'orders' => [['uuid' => 'px_1', 'type' => 'isp', 'status' => 'active', 'price_cents' => 300]],
            ]],
        ]);
        $list = $this->client($transport)->proxy->list();

        $this->assertSame('u', $list->residential->username);
        $this->assertSame('active', $list->subscription->status);
        $this->assertSame(15, $list->subscription->discountPct);
        $this->assertCount(1, $list->orders);
        $this->assertSame('px_1', $list->orders[0]->uuid);
    }

    public function test_extend_and_auto_renew_hit_order_routes(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['uuid' => 'px_1', 'type' => 'isp', 'status' => 'active', 'price_cents' => 300]],
            ['status' => 200, 'body' => ['uuid' => 'px_1', 'type' => 'isp', 'status' => 'active', 'price_cents' => 300, 'auto_extend' => true]],
        ]);
        $client = $this->client($transport);

        $client->proxy->extend('px_1', 30);
        $this->assertSame('https://x.test/api/v1/proxy/orders/px_1/extend', $calls[0]['url']);
        $this->assertSame(['days' => 30], json_decode((string) $calls[0]['body'], true));

        $order = $client->proxy->autoRenew('px_1', true);
        $this->assertSame('https://x.test/api/v1/proxy/orders/px_1/auto-renew', $calls[1]['url']);
        $this->assertSame(['enabled' => true], json_decode((string) $calls[1]['body'], true));
        $this->assertTrue($order->autoExtend);
    }

    public function test_subscription_pause_posts_to_route(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['status' => 'paused', 'gb' => 10, 'discount_pct' => 15]],
        ]);
        $sub = $this->client($transport)->proxy->subscriptionPause();

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/v1/proxy/subscription/pause', $calls[0]['url']);
        $this->assertSame('paused', $sub->status);
    }
}
