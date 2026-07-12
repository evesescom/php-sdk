<?php

declare(strict_types=1);

namespace Eveses\Sdk\Tests;

use Eveses\Sdk\Eveses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the consolidated ``numbers`` module + the new v0.4.0
 * modules (orders / pricing / quotas / account / captcha usage). Injects a
 * fake transport (no network); asserts URL / method / body on the wire.
 */
final class NumbersTest extends TestCase
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

    public function test_create_posts_to_numbers_orders(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['data' => ['order_id' => 'o1', 'status' => 'pending', 'phone' => '+380']]],
        ]);
        $order = $this->client($transport)->numbers->create([
            'country' => 'ua', 'service' => 'telegram', 'idempotency_key' => 'idem-1',
        ]);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/v1/numbers/orders', $calls[0]['url']);
        $this->assertSame('idem-1', $calls[0]['headers']['Idempotency-Key']);
        $this->assertSame('o1', $order->orderId);
        $this->assertSame('+380', $order->phone);
    }

    public function test_order_actions_hit_v1_routes(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o1', 'status' => 'canceled']]],
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o1', 'status' => 'completed']]],
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o1', 'status' => 'pending']]],
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o2', 'status' => 'pending']]],
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o1', 'status' => 'active']]],
        ]);
        $c = $this->client($transport);

        $c->numbers->cancel('o1');
        $c->numbers->finish('o1');
        $c->numbers->retry('o1');
        $c->numbers->repeat('o1');
        $c->numbers->autoRenew('o1', true);

        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/cancel', $calls[0]['url']);
        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/finish', $calls[1]['url']);
        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/retry', $calls[2]['url']);
        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/repeat', $calls[3]['url']);
        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/auto-renew', $calls[4]['url']);
        $this->assertSame(['enabled' => true], json_decode((string) $calls[4]['body'], true));
    }

    public function test_batch_and_sms_and_summary(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['created' => 2]],
            ['status' => 200, 'body' => ['data' => ['order_id' => 'o1', 'fresh' => [['id' => 1, 'text' => 'hi']]]]],
            ['status' => 200, 'body' => ['total' => 5]],
        ]);
        $c = $this->client($transport);

        $c->numbers->batch([['country' => 'ua', 'service' => 'tg']], ['idempotency_key' => 'b1']);
        $sms = $c->numbers->sms('o1');
        $c->numbers->summary();

        $this->assertSame('https://x.test/api/v1/numbers/orders/batch', $calls[0]['url']);
        $this->assertSame('b1', $calls[0]['headers']['Idempotency-Key']);
        $this->assertSame('https://x.test/api/v1/numbers/orders/o1/sms', $calls[1]['url']);
        $this->assertSame('hi', $sms->fresh[0]->text);
        $this->assertSame('https://x.test/api/v1/numbers/orders/summary', $calls[2]['url']);
    }

    public function test_carriers_and_states_send_country(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['carriers' => []]],
            ['status' => 200, 'body' => ['states' => []]],
        ]);
        $c = $this->client($transport);

        $c->numbers->carriers(['mode' => 'activation', 'country' => 'US']);
        $c->numbers->states(['mode' => 'activation', 'country' => 'US']);

        $this->assertStringStartsWith('https://x.test/api/v1/numbers/carriers?', $calls[0]['url']);
        $this->assertStringContainsString('country=us', $calls[0]['url']);
        $this->assertStringStartsWith('https://x.test/api/v1/numbers/states?', $calls[1]['url']);
        $this->assertStringContainsString('country=us', $calls[1]['url']);
    }

    public function test_orders_pricing_quotas_hit_aggregate_routes(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['data' => [], 'meta' => ['has_more' => false]]],
            ['status' => 200, 'body' => ['source' => 'proxy', 'id' => 'u1']],
            ['status' => 200, 'body' => ['currency' => 'USD']],
            ['status' => 200, 'body' => ['proxy' => []]],
        ]);
        $c = $this->client($transport);

        $c->orders->list(['service' => 'proxy', 'limit' => 50]);
        $one = $c->orders->get('u1');
        $c->pricing->all();
        $c->quotas->all();

        $this->assertStringStartsWith('https://x.test/api/v1/orders?', $calls[0]['url']);
        $this->assertStringContainsString('service=proxy', $calls[0]['url']);
        $this->assertStringContainsString('limit=50', $calls[0]['url']);
        $this->assertSame('https://x.test/api/v1/orders/u1', $calls[1]['url']);
        $this->assertSame('u1', $one['id']);
        $this->assertSame('https://x.test/api/v1/pricing', $calls[2]['url']);
        $this->assertSame('https://x.test/api/v1/quotas', $calls[3]['url']);
    }

    public function test_account_me_exposes_features_and_abilities(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => [
                'id' => 7,
                'abilities' => ['*'],
                'features' => ['trial' => true, 'captcha' => false],
            ]],
        ]);
        $me = $this->client($transport)->account->me();

        $this->assertSame('https://x.test/api/v1/me', $calls[0]['url']);
        $this->assertSame(['*'], $me->abilities);
        $this->assertTrue($me->features->trial);
        $this->assertFalse($me->features->captcha);
        $this->assertSame(7, $me->raw['id']);
    }

    public function test_captcha_rates_and_usage(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['rates' => []]],
            ['status' => 200, 'body' => ['data' => [], 'meta' => ['has_more' => false]]],
        ]);
        $c = $this->client($transport);

        $c->captcha->rates();
        $c->captcha->usage(['status' => 'ready', 'limit' => 10]);

        $this->assertSame('https://x.test/api/v1/captcha/rates', $calls[0]['url']);
        $this->assertStringStartsWith('https://x.test/api/v1/captcha/usage?', $calls[1]['url']);
        $this->assertStringContainsString('status=ready', $calls[1]['url']);
        $this->assertStringContainsString('limit=10', $calls[1]['url']);
    }
}
