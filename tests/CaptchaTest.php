<?php

declare(strict_types=1);

namespace Eveses\Sdk\Tests;

use Eveses\Sdk\Eveses;
use Eveses\Sdk\Exceptions\EvesesException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the captcha module. Injects a fake transport (no network);
 * canned responses use retry_after=0 so the blocking poll never actually sleeps.
 */
final class CaptchaTest extends TestCase
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

    public function test_solve_polls_until_ready(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['task_id' => 7, 'status' => 'queued', 'price_micro_usd' => 3392, 'retry_after' => 0]],
            ['status' => 200, 'body' => ['status' => 'processing', 'retry_after' => 0]],
            ['status' => 200, 'body' => ['status' => 'ready', 'solution' => 'TOK', 'retry_after' => 0]],
        ]);
        $client = new Eveses([
            'api_key' => 'k',
            'base_url' => 'https://x.test',
            'transport' => $transport,
        ]);

        $res = $client->captcha->solve('RecaptchaV2TaskProxyless', ['websiteURL' => 'x', 'websiteKey' => 'k']);

        $this->assertSame(7, $res->taskId);
        $this->assertSame('ready', $res->status);
        $this->assertSame('TOK', $res->solution);
        $this->assertSame(3392, $res->priceMicroUsd);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://x.test/api/account/captcha/solve', $calls[0]['url']);
        $this->assertSame('https://x.test/api/account/captcha/result/7', $calls[1]['url']);
    }

    public function test_solve_throws_on_failure(): void
    {
        [$transport] = $this->fakeTransport([
            ['status' => 201, 'body' => ['task_id' => 9, 'status' => 'queued', 'retry_after' => 0]],
            ['status' => 200, 'body' => ['status' => 'failed', 'error' => 'ERROR_CAPTCHA_UNSOLVABLE', 'retry_after' => 0]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $this->expectException(EvesesException::class);
        $this->expectExceptionMessageMatches('/ERROR_CAPTCHA_UNSOLVABLE/');
        $client->captcha->solve('ImageToTextTask', []);
    }

    public function test_solve_sends_idempotency_key(): void
    {
        [$transport, $calls] = $this->fakeTransport([
            ['status' => 201, 'body' => ['task_id' => 3, 'status' => 'ready', 'solution' => 'A', 'retry_after' => 0]],
        ]);
        $client = new Eveses(['api_key' => 'k', 'base_url' => 'https://x.test', 'transport' => $transport]);

        $res = $client->captcha->solve('ImageToTextTask', [], ['idempotency_key' => 'idem-c']);

        $this->assertSame('A', $res->solution);
        $this->assertSame('idem-c', $calls[0]['headers']['Idempotency-Key']);
    }
}
