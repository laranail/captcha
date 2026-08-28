<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\Locale;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Adapters\Math\MathProblem;

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

        return view('laranail-captcha::components.captcha', [
            'widget'           => $widget,
            'widgetAttributes' => $attributes,
            'scriptUrl'        => $widget->scriptUrl,
            // reCAPTCHA v3 and v2-invisible have nothing to click: the token only exists once
            // `grecaptcha.execute()` has run. A container alone renders an empty div and the form
            // submits with no captcha at all — which is why this is wired here rather than left
            // as an instruction in the docs.
            'executesOnSubmit' => $captcha->provider()->requiresExplicitExecution(),
            'siteKey'          => $attributes['data-sitekey'] ?? null,
            'action'           => $attributes['data-action'] ?? 'submit',
            // Per-instance configuration travels as data attributes and the runtime is emitted
            // once per page, rather than a copy of the script per widget. Two widgets on a page
            // would otherwise install two MutationObservers and two morph hooks, and each would
            // process the other's container.
            'runtime' => [
                'provider'     => $captcha->provider()->value,
                'reset'        => $this->resetFunction($captcha->provider()),
                'skipMorph'    => $captcha->provider()->hasLiveVendorState(),
                'selfHosted'   => $captcha->provider()->isSelfHosted(),
                'challengeUrl' => $widget->attributes()['data-challenge-url']
                    ?? $widget->attributes()['challengeurl']
                    ?? null,
            ],
            'lang'           => Locale::sanitise($this->lang),
            'nonce'          => $this->nonce,
            'problem'        => $challenge instanceof MathProblem ? $challenge : null,
            'challengeToken' => $challenge instanceof MathProblem
                ? base64_encode((string) json_encode([
                    'id'        => $challenge->id,
                    'expires'   => $challenge->expiresAt->getTimestamp(),
                    'signature' => $challenge->signature,
                ]))
                : null,
            'label' => $this->label,
        ]);
    }

    /**
     * The global function that resets this provider's widget.
     *
     * Configuration wins over the built-in, because two providers cannot be expressed as a global
     * at all. Friendly Captcha's reset lives on a `WidgetHandle` returned by `sdk.createWidget()`,
     * and Arkose's on the enforcement instance handed to the application's own `setupEnforcement`
     * callback — in both cases the handle exists only inside code this package does not write.
     *
     * So the application exposes its own function and names it here. That is a seam rather than a
     * guess: inventing `frcaptcha.reset` would resolve to nothing and produce a callback that
     * silently does not reset, which is worse than no reset at all because it looks handled.
     */
    private function resetFunction(Provider $provider): ?string
    {
        $configured = config('laranail.captcha.providers.' . $provider->optionsKey() . '.reset_function');

        return is_string($configured) && $configured !== '' ? $configured : $provider->resetFunction();
    }
}
