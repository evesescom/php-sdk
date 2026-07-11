<?php

declare(strict_types=1);

namespace Eveses\Sdk\Modules;

use Eveses\Sdk\Exceptions\EvesesException;
use Eveses\Sdk\Http\Client;

/**
 * Captcha-solving namespace. Resells 2captcha, billed pay-per-use from the
 * wallet (count-on-success). Hits ``/api/account/captcha/*``.
 */
final class Captcha
{
    /** @var callable(int):void */
    private $sleeper;

    /** @param callable(int):void|null $sleeper seconds → void (injectable for tests) */
    public function __construct(private readonly Client $http, ?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * Blocking solve: submit the task, then poll the result endpoint honouring
     * the API's ``retry_after`` until the task is ``ready``/``failed`` or
     * ``timeoutSec`` elapses. Returns an object with ``taskId``, ``status`` and
     * ``solution``; throws EvesesException on failure/timeout.
     *
     * @param  array<string,mixed>  $params
     * @param  array{callback_url?:string, idempotency_key?:string, timeout_sec?:int}  $opts
     */
    public function solve(string $type, array $params = [], array $opts = []): object
    {
        $headers = [];
        if (isset($opts['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $opts['idempotency_key'];
        }

        $body = ['type' => $type, 'params' => $params];
        if (isset($opts['callback_url'])) {
            $body['callback_url'] = (string) $opts['callback_url'];
        }

        $started = (array) $this->http->request('POST', '/api/account/captcha/solve', null, $body, $headers);

        $taskId = (int) ($started['task_id'] ?? 0);
        $priceMicroUsd = isset($started['price_micro_usd']) ? (int) $started['price_micro_usd'] : null;
        $retryAfter = (int) ($started['retry_after'] ?? 5);
        $deadline = time() + (int) ($opts['timeout_sec'] ?? 180);
        $status = (string) ($started['status'] ?? 'queued');

        if ($status === 'ready' || $status === 'failed') {
            $solution = isset($started['solution']) && is_string($started['solution']) ? $started['solution'] : null;
            $error = isset($started['error']) && is_string($started['error']) ? $started['error'] : null;

            return $this->finalise($taskId, $status, $solution, $error, $priceMicroUsd);
        }

        while (true) {
            ($this->sleeper)($retryAfter);

            $res = (array) $this->http->request('GET', '/api/account/captcha/result/'.rawurlencode((string) $taskId));
            $retryAfter = isset($res['retry_after']) ? (int) $res['retry_after'] : $retryAfter;
            $status = (string) ($res['status'] ?? 'processing');

            if ($status === 'ready' || $status === 'failed') {
                $solution = isset($res['solution']) && is_string($res['solution']) ? $res['solution'] : null;
                $error = isset($res['error']) && is_string($res['error']) ? $res['error'] : null;

                return $this->finalise($taskId, $status, $solution, $error, $priceMicroUsd);
            }

            if (time() >= $deadline) {
                throw new EvesesException("Captcha task {$taskId} timed out before resolving", 0);
            }
        }
    }

    private function finalise(int $taskId, string $status, ?string $solution, ?string $error, ?int $priceMicroUsd): object
    {
        if ($status === 'failed') {
            throw new EvesesException("Captcha task {$taskId} failed: ".($error ?? 'unknown error'), 0);
        }

        return (object) [
            'taskId' => $taskId,
            'status' => $status,
            'solution' => $solution,
            'error' => $error,
            'priceMicroUsd' => $priceMicroUsd,
        ];
    }
}
