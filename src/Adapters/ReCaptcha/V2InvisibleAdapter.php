<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\ReCaptcha;

use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * reCAPTCHA v2 in invisible mode.
 *
 * Same verification as the checkbox; the difference is entirely client-side. There is nothing for
 * the visitor to click, so the token is minted by `grecaptcha.execute()` when the form is
 * submitted — which is why {@see Provider::requiresExplicitExecution()} is true for this case and
 * why a bare container component is not enough to make it work.
 */
final class V2InvisibleAdapter extends V2Adapter
{
    public function provider(): Provider
    {
        return Provider::ReCaptchaV2Invisible;
    }

    protected function widgetAttributes(): array
    {
        return [
            ...parent::widgetAttributes(),
            'data-size'  => 'invisible',
            'data-badge' => $this->stringOption('badge') ?? 'bottomright',
        ];
    }
}
