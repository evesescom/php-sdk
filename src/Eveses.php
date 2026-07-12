<?php

declare(strict_types=1);

namespace Eveses\Sdk;

use Eveses\Sdk\Exceptions\EvesesException;
use Eveses\Sdk\Http\Client;
use Eveses\Sdk\Modules\Account;
use Eveses\Sdk\Modules\Captcha;
use Eveses\Sdk\Modules\Emails;
use Eveses\Sdk\Modules\Numbers;
use Eveses\Sdk\Modules\Orders;
use Eveses\Sdk\Modules\Pricing;
use Eveses\Sdk\Modules\Proxy;
use Eveses\Sdk\Modules\Quotas;
use Eveses\Sdk\Modules\Trial;
use Eveses\Sdk\Modules\Wallet;
use Eveses\Sdk\Modules\Webhooks;
use Eveses\Sdk\Modules\WebUnblocker;

/**
 * Eveses SDK client.
 *
 * Mirrors the surface of the JS / Python SDKs: ``numbers``, ``wallet``,
 * ``orders``, ``pricing``, ``quotas`` modules + a static ``Webhooks::verify``
 * for signature checks.
 *
 *   $client = new Eveses\Sdk\Eveses(['api_key' => getenv('EVESES_API_KEY')]);
 *   $order  = $client->numbers->create(['country' => 'ua', 'service' => 'telegram']);
 *   $sms    = $client->numbers->sms($order->orderId);
 *   $bal    = $client->wallet->balance();
 *   $svcs   = $client->numbers->services(['mode' => 'activation', 'country' => 'ua']);
 *
 * Construction options (array key => meaning):
 *   - ``api_key``         (string, required) Sanctum personal-access token (kind=api_key).
 *   - ``base_url``        (string)           Defaults to ``https://api.eveses.com``.
 *   - ``timeout``         (int seconds)      Per-request timeout, default 30.
 *   - ``user_agent``      (string)           Override the User-Agent header.
 *   - ``default_headers`` (array<string,string>) Extra headers merged into every request.
 *   - ``transport``       (callable)         Test-only HTTP transport hook.
 */
final class Eveses
{
    public const VERSION = '0.4.0';

    private const DEFAULT_BASE_URL = 'https://api.eveses.com';

    private const DEFAULT_TIMEOUT_S = 30;

    private const DEFAULT_USER_AGENT = 'eveses-php/0.4.0';

    public readonly Numbers $numbers;

    public readonly Wallet $wallet;

    public readonly Account $account;

    public readonly Captcha $captcha;

    public readonly Emails $emails;

    public readonly Orders $orders;

    public readonly Pricing $pricing;

    public readonly Proxy $proxy;

    public readonly Quotas $quotas;

    public readonly Trial $trial;

    public readonly WebUnblocker $webUnblocker;

    private readonly Client $http;

    /**
     * @param array{
     *     api_key: string,
     *     base_url?: string,
     *     timeout?: int,
     *     user_agent?: string,
     *     default_headers?: array<string,string>,
     *     transport?: callable,
     * } $opts
     */
    public function __construct(array $opts)
    {
        $apiKey = (string) ($opts['api_key'] ?? '');
        if ($apiKey === '') {
            throw new EvesesException('api_key is required', 0);
        }

        $this->http = new Client(
            apiKey: $apiKey,
            baseUrl: (string) ($opts['base_url'] ?? self::DEFAULT_BASE_URL),
            timeoutSeconds: (int) ($opts['timeout'] ?? self::DEFAULT_TIMEOUT_S),
            userAgent: (string) ($opts['user_agent'] ?? self::DEFAULT_USER_AGENT),
            defaultHeaders: (array) ($opts['default_headers'] ?? []),
            transport: $opts['transport'] ?? null,
        );

        $this->numbers = new Numbers($this->http);
        $this->wallet = new Wallet($this->http);
        $this->account = new Account($this->http);
        $this->captcha = new Captcha($this->http);
        $this->emails = new Emails($this->http);
        $this->orders = new Orders($this->http);
        $this->pricing = new Pricing($this->http);
        $this->proxy = new Proxy($this->http);
        $this->quotas = new Quotas($this->http);
        $this->trial = new Trial($this->http);
        $this->webUnblocker = new WebUnblocker($this->http);
    }

    /**
     * Convenience accessor mirroring the JS/Python static webhook helper.
     * Equivalent to calling ``Eveses\Sdk\Modules\Webhooks::verify(...)``
     * directly.
     */
    public static function verifyWebhook(
        string $rawBody,
        ?string $signatureHeader,
        ?string $timestampHeader,
        string $secret,
        int $toleranceSeconds = 300,
    ): bool {
        return Webhooks::verify(
            rawBody: $rawBody,
            signatureHeader: $signatureHeader,
            timestampHeader: $timestampHeader,
            secret: $secret,
            toleranceSeconds: $toleranceSeconds,
        );
    }
}
