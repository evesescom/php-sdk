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

        $res = $client->numbers->countries(['mode' => 'activation']);

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

        $res = $client->numbers->services(['mode' => 'activation', 'country' => 'UA', 'currency' => 'usd']);

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

        $res = $client->numbers->pricing([
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
        $client->numbers->pricing(['country' => '', 'service' => 'telegram']);
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
