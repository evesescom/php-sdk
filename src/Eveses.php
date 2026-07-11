<?php

declare(strict_types=1);

namespace Eveses\Sdk;

use Eveses\Sdk\Exceptions\EvesesException;
use Eveses\Sdk\Http\Client;
use Eveses\Sdk\Modules\Activations;
use Eveses\Sdk\Modules\Captcha;
use Eveses\Sdk\Modules\Catalog;
use Eveses\Sdk\Modules\Emails;
use Eveses\Sdk\Modules\Fingerprints;
use Eveses\Sdk\Modules\Proxy;
use Eveses\Sdk\Modules\Trial;
use Eveses\Sdk\Modules\Wallet;
use Eveses\Sdk\Modules\Webhooks;
use Eveses\Sdk\Modules\WebUnblocker;

/**
 * Eveses SDK client.
 *
 * Mirrors the surface of the JS / Python SDKs: ``activations``, ``wallet``,
 * ``catalog`` modules + a static ``Webhooks::verify`` for signature checks.
 *
 *   $client = new Eveses\Sdk\Eveses(['api_key' => getenv('EVESES_API_KEY')]);
 *   $order  = $client->activations->create(['country' => 'ua', 'service' => 'telegram']);
 *   $sms    = $client->activations->sms($order->orderId);
 *   $bal    = $client->wallet->balance();
 *   $svcs   = $client->catalog->services(['mode' => 'activation', 'country' => 'ua']);
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
    public const VERSION = '0.3.0';

    private const DEFAULT_BASE_URL = 'https://api.eveses.com';

    private const DEFAULT_TIMEOUT_S = 30;

    private const DEFAULT_USER_AGENT = 'eveses-php/0.3.0';

    public readonly Activations $activations;

    public readonly Wallet $wallet;

    public readonly Catalog $catalog;

    public readonly Captcha $captcha;

    public readonly Emails $emails;

    public readonly Fingerprints $fingerprints;

    public readonly Proxy $proxy;

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

        $this->activations = new Activations($this->http);
        $this->wallet = new Wallet($this->http);
        $this->catalog = new Catalog($this->http);
        $this->captcha = new Captcha($this->http);
        $this->emails = new Emails($this->http);
        $this->fingerprints = new Fingerprints($this->http);
        $this->proxy = new Proxy($this->http);
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
