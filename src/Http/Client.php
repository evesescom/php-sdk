<?php

declare(strict_types=1);

namespace Eveses\Sdk\Http;

use Eveses\Sdk\Exceptions\EvesesAuthException;
use Eveses\Sdk\Exceptions\EvesesException;
use Eveses\Sdk\Exceptions\EvesesRateLimitException;

/**
 * Tiny HTTP client backed by ext-curl.
 *
 * The "transport" indirection is what makes the SDK testable without Guzzle
 * or Mockery. By default we use the real ext-curl path; tests inject a
 * callable that records the last request and returns a canned response.
 *
 * Public-API contract (for tests + callers):
 *   - Bearer auth header on every request
 *   - Idempotency-Key header passthrough (set by the Activations module)
 *   - One automatic retry on 429, honouring Retry-After (capped at 60s)
 *   - Typed exceptions on non-2xx; ``$status`` preserved on the exception
 */
final class Client
{
    /**
     * @var callable(array<string,mixed>): array{status:int, headers:array<string,string>, body:string}
     */
    private $transport;

    /** @var array<string,string> */
    private array $defaultHeaders;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly string $userAgent,
        array $defaultHeaders = [],
        ?callable $transport = null,
    ) {
        $this->defaultHeaders = $defaultHeaders;
        $this->transport = $transport ?? $this->makeCurlTransport();
    }

    /**
     * Perform a single authenticated request and return the parsed JSON body
     * (or null for empty bodies).
     *
     * @param  string  $method  HTTP method (GET/POST/...).
     * @param  string  $path  Path on the API host (leading ``/`` optional).
     * @param  array<string,scalar|null>|null  $query  Query-string params; nulls are dropped.
     * @param  array<string,mixed>|null  $body  JSON body for POST/PUT/PATCH.
     * @param  array<string,string>|null  $headers  Per-request header overrides.
     */
    public function request(
        string $method,
        string $path,
        ?array $query = null,
        ?array $body = null,
        ?array $headers = null,
    ): mixed {
        $url = $this->buildUrl($path, $query);
        $hdr = array_merge(
            [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent,
            ],
            $this->defaultHeaders,
            $headers ?? [],
        );

        $bodyStr = null;
        if ($body !== null) {
            $hdr['Content-Type'] ??= 'application/json';
            $bodyStr = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($bodyStr === false) {
                throw new EvesesException('Failed to encode request body as JSON', 0);
            }
        }

        return $this->executeWithRetry($method, $url, $hdr, $bodyStr, attempt: 0);
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function executeWithRetry(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $attempt,
    ): mixed {
        $response = ($this->transport)([
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
        ]);

        $status = (int) ($response['status'] ?? 0);
        $resHeaders = self::lowerHeaders($response['headers'] ?? []);
        $rawBody = (string) ($response['body'] ?? '');

        if ($status === 429 && $attempt === 0) {
            $retry = self::parseRetryAfter($resHeaders['retry-after'] ?? null);
            if ($retry > 0) {
                // Cap at 60s per spec, sleep, retry once.
                sleep(min($retry, 60));
            }

            return $this->executeWithRetry($method, $url, $headers, $body, $attempt + 1);
        }

        return $this->parseResponse($status, $resHeaders, $rawBody);
    }

    /**
     * @param  array<string,string>  $headers  Lower-cased header keys.
     */
    private function parseResponse(int $status, array $headers, string $rawBody): mixed
    {
        $contentType = $headers['content-type'] ?? '';
        $parsed = null;
        if ($rawBody !== '') {
            if (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($rawBody, true);
                $parsed = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            } else {
                $parsed = $rawBody;
            }
        }

        if ($status >= 200 && $status < 300) {
            return $parsed;
        }

        $message = self::extractMessage($parsed) ?? sprintf('HTTP %d', $status);

        if ($status === 401 || $status === 403) {
            throw new EvesesAuthException($message, $status, $parsed);
        }
        if ($status === 429) {
            $retry = self::parseRetryAfter($headers['retry-after'] ?? null);
            throw new EvesesRateLimitException($message, $retry, $parsed);
        }
        // 400, 404, 422, 5xx, other — collapsed onto the base class.
        // The status code is preserved on the exception for callers who want to branch.
        throw new EvesesException($message, $status, self::codeFor($status), $parsed);
    }

    private function buildUrl(string $path, ?array $query): string
    {
        $normalised = str_starts_with($path, '/') ? $path : '/'.$path;
        $url = rtrim($this->baseUrl, '/').$normalised;
        if ($query) {
            $clean = [];
            foreach ($query as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $clean[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
            if ($clean !== []) {
                $url .= '?'.http_build_query($clean);
            }
        }

        return $url;
    }

    /**
     * @return callable(array<string,mixed>): array{status:int, headers:array<string,string>, body:string}
     */
    private function makeCurlTransport(): callable
    {
        return static function (array $req): array {
            $ch = curl_init();
            $headers = [];
            foreach (($req['headers'] ?? []) as $name => $value) {
                $headers[] = $name.': '.$value;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $req['url'],
                CURLOPT_CUSTOMREQUEST => $req['method'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => (int) ($req['timeout'] ?? 30),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            if (($req['body'] ?? null) !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new EvesesException('Network error: '.$err, 0);
            }
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $rawHeaders = substr((string) $raw, 0, (int) $headerSize);
            $body = substr((string) $raw, (int) $headerSize);

            return [
                'status' => (int) $status,
                'headers' => self::parseHeaderBlock($rawHeaders),
                'body' => (string) $body,
            ];
        };
    }

    /**
     * @return array<string,string>
     */
    private static function parseHeaderBlock(string $block): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $idx = strpos($line, ':');
            if ($idx === false) {
                continue;
            }
            $name = trim(substr($line, 0, $idx));
            $value = trim(substr($line, $idx + 1));
            if ($name !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $headers
     * @return array<string,string>
     */
    private static function lowerHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower((string) $k)] = (string) $v;
        }

        return $out;
    }

    private static function parseRetryAfter(?string $raw): int
    {
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (ctype_digit($raw)) {
            $n = (int) $raw;

            return $n < 0 ? 1 : min($n, 60);
        }

        // HTTP-date form — fall back to a small default.
        return 1;
    }

    private static function extractMessage(mixed $body): ?string
    {
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message'])) {
                return $body['message'];
            }
            if (isset($body['error']) && is_string($body['error'])) {
                return $body['error'];
            }
        }

        return null;
    }

    private static function codeFor(int $status): ?string
    {
        return match (true) {
            $status === 404 => 'not_found',
            $status === 422 => 'validation_failed',
            $status === 400 => 'validation_failed',
            $status >= 500 => 'server_error',
            default => null,
        };
    }
}
