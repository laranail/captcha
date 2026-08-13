<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\ReCaptcha;

use Simtabi\Laranail\Captcha\Actions\VerifyCaptcha;
use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * reCAPTCHA v3 — score only, never interrupts.
 *
 * The script is loaded with `?render={siteKey}` and the token is minted by `grecaptcha.execute()`
 * with an action name; there is no widget to place. The score comes back on the verification
 * response and is enforced by {@see VerifyCaptcha} against the
 * configured threshold.
 *
 * That enforcement is the whole point of the version. The package this replaces supported v3 by
 * reading `success`, which is true for a token minted by any visitor including a bot scoring 0.1 —
 * so v3 protection amounted to checking that the token was well-formed.
 */
final class V3Adapter extends V2Adapter
{
    public function provider(): Provider
    {
        return Provider::ReCaptchaV3;
    }

    protected function widgetAttributes(): array
    {
        return [
            'data-sitekey' => $this->credentials->siteKey,
            'data-action' => $this->stringOption('action'),
        ];
    }

    protected function scriptUrl(): string
    {
        return self::SCRIPT_URL . '?render=' . rawurlencode($this->credentials->siteKey);
    }
}
