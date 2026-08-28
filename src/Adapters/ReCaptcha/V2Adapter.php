<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\ReCaptcha;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\Locale;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Simtabi\Laranail\Captcha\Adapters\Concerns\SiteVerifyAdapter;

/**
 * reCAPTCHA v2, the checkbox.
 *
 * The base for the v2-invisible and v3 adapters: all three post to the same siteverify endpoint
 * with the same payload and differ only in how the widget renders and what the response carries.
 * Enterprise does not extend this — it is a different API entirely.
 */
class V2Adapter extends SiteVerifyAdapter
{
    public const string VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public const string SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';

    public function provider(): Provider
    {
        return Provider::ReCaptchaV2;
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: $this->provider(),
            instanceId: $instanceId,
            containerClass: 'g-recaptcha',
            attributes: $this->widgetAttributes(),
            scriptUrl: $this->scriptUrl(),
        );
    }

    protected function verifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    /**
     * @return array<string, string|null>
     */
    protected function widgetAttributes(): array
    {
        return [
            'data-sitekey' => $this->credentials->siteKey,
            'data-theme'   => $this->stringOption('theme'),
            'data-size'    => $this->stringOption('size'),
        ];
    }

    protected function scriptUrl(): string
    {
        return self::SCRIPT_URL . '?hl=' . rawurlencode(
            Locale::sanitise($this->stringOption('language')) ?? 'en',
        );
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
