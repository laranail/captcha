<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Turnstile;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

final class TurnstileAdapter extends SiteVerifyAdapter
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const string SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public function provider(): Provider
    {
        return Provider::Turnstile;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::Turnstile,
            instanceId: $instanceId,
            containerClass: 'cf-turnstile',
            attributes: [
                'data-sitekey' => $this->credentials->siteKey,
                'data-theme' => $this->stringOption('theme'),
                'data-size' => $this->stringOption('size'),
                // The locale Turnstile actually reads. The package this replaces documented
                // locale support "via the container component" and then never emitted this
                // attribute, so the setting did nothing for the default provider.
                'data-language' => $this->stringOption('language'),
                'data-action' => $this->stringOption('action'),
            ],
            scriptUrl: self::SCRIPT_URL,
        );
    }

    protected function verifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    protected function payload(string $token, VerificationContext $context): array
    {
        $payload = parent::payload($token, $context);

        if ($context->idempotencyKey !== null) {
            $payload['idempotency_key'] = $context->idempotencyKey;
        }

        return $payload;
    }

    /**
     * The one provider where a retry is safe, and only when the caller supplied a key.
     *
     * Cloudflare treats a repeat siteverify carrying the same `idempotency_key` as the same
     * attempt rather than a second redemption, which is what makes the difference between
     * recovering from a dropped response and rejecting the visitor with `timeout-or-duplicate`.
     * Without the key this behaves like every other adapter and does not retry.
     */
    protected function send(string $token, VerificationContext $context): Response
    {
        $client = $context->idempotencyKey !== null
            ? $this->http->retryable()
            : $this->http->request();

        return $client->asForm()->post($this->verifyUrl(), $this->payload($token, $context));
    }

    protected function client(): PendingRequest
    {
        return $this->http->request();
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
