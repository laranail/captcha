<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Simtabi\Laranail\Captcha\Adapters\Math\MathProblem;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Support\Locale;

/**
 * `<x-captcha />` — the whole thing, in one tag.
 *
 * Drop it inside a form, add `'captcha' => 'captcha'` to the validation rules, and you are done —
 * whichever of the eleven providers is configured, with or without JavaScript, with or without an
 * account anywhere. Switching provider later is a config line and nothing else; this markup does
 * not change.
 *
 * `<x-captcha-js />` and `<x-captcha-container />` remain for layouts that need the script in
 * `<head>` and the widget further down. This exists because most forms do not, and asking someone
 * to place two tags correctly is the difference between a package that gets used and one that gets
 * copied from Stack Overflow.
 */
final class Captcha extends Component
{
    public function __construct(
        public ?string $theme = null,
        public ?string $size = null,
        public ?string $lang = null,
        public ?string $nonce = null,
        public ?string $label = null,
    ) {}

    public function render(): View
    {
        $captcha = app(CaptchaService::class);
        $widget = $captcha->widget();

        // Server-rendered providers hand the visitor a question directly, with no script and no
        // round trip. Everything else gets the vendor's widget and its script tag.
        $challenge = $captcha->provider()->isSelfHosted() ? $captcha->issueChallenge() : null;

        $attributes = $widget->attributes();

        if ($this->theme !== null) {
            $attributes['data-theme'] = $this->theme;
        }

        if ($this->size !== null) {
            $attributes['data-size'] = $this->size;
        }

        return view('captcha::components.captcha', [
            'widget' => $widget,
            'widgetAttributes' => $attributes,
            'scriptUrl' => $widget->scriptUrl,
            'lang' => Locale::sanitise($this->lang),
            'nonce' => $this->nonce,
            'problem' => $challenge instanceof MathProblem ? $challenge : null,
            'challengeToken' => $challenge instanceof MathProblem
                ? base64_encode((string) json_encode([
                    'id' => $challenge->id,
                    'expires' => $challenge->expiresAt->getTimestamp(),
                    'signature' => $challenge->signature,
                ]))
                : null,
            'label' => $this->label,
        ]);
    }
}
