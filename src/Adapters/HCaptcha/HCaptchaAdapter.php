<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\HCaptcha;

use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\Locale;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;

final class HCaptchaAdapter extends SiteVerifyAdapter
{
    /**
     * The documented host.
     *
     * The package this replaces posted to `https://hcaptcha.com/siteverify`, which is not the
     * endpoint hCaptcha documents. It answers today by redirect, and a POST body does not
     * necessarily survive one.
     */
    public const string VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    public const string SCRIPT_URL = 'https://js.hcaptcha.com/1/api.js';

    public function provider(): Provider
    {
        return Provider::HCaptcha;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::HCaptcha,
            instanceId: $instanceId,
            containerClass: 'h-captcha',
            attributes: [
                'data-sitekey' => $this->credentials->siteKey,
                'data-theme' => $this->stringOption('theme'),
                'data-size' => $this->stringOption('size'),
            ],
            scriptUrl: self::SCRIPT_URL.'?hl='.rawurlencode(
                Locale::sanitise($this->stringOption('language')) ?? 'en',
            ),
        );
    }

    protected function verifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    /**
     * hCaptcha accepts the site key alongside the secret, and sending it binds the token.
     *
     * Without it, a token minted against any site key belonging to the same account verifies here
     * — so a low-value public form on another property becomes a token mint for this one.
     */
    protected function payload(string $token, VerificationContext $context): array
    {
        return [...parent::payload($token, $context), 'sitekey' => $this->credentials->siteKey];
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
