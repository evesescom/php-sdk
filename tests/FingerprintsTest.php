<?php

declare(strict_types=1);

namespace Eveses\Sdk\Tests;

use Eveses\Sdk\Eveses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the fingerprints module. Injects a fake transport (no network).
 */
final class FingerprintsTest extends TestCase
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

    public function test_generate_returns_payload_and_price(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['fingerprint' => ['id' => 'fp_1', 'userAgent' => ['value' => 'UA']], 'price_micro_usd' => 1600]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->fingerprints->generate(['country' => 'US']);

        $this->assertSame('fp_1', $res->fingerprint['id']);
        $this->assertSame(1600, $res->priceMicroUsd);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/fingerprints/generate', $calls[0]['url']);
    }

    public function test_random_hits_random_endpoint(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 200, 'body' => ['fingerprint' => ['id' => 'fp_r']]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->fingerprints->random(['tags' => 'macOS']);

        $this->assertSame('fp_r', $res->fingerprint['id']);
        $this->assertSame('https://x.test/api/account/fingerprints/random', $calls[0]['url']);
    }
}
