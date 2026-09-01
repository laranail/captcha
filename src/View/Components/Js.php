<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Support\Locale;

/**
 * `<x-captcha-js />` — the active provider's script tag.
 *
 * **Returns a View, never a string.** A component whose `render()` returns a string has that
 * string written to a file and compiled as a Blade template
 * (`Illuminate\View\Component::createBladeViewFromString`). The package this replaces returned the
 * script tag as a string with the locale interpolated into it unescaped, so
 * `<x-captcha-js :lang="$request->input('lang')" />` gave an attacker HTML injection into the
 * script tag, Blade injection through `{{ }}` and `@php`, and a compiled view file written per
 * distinct input.
 *
 * Returning a view removes the compilation path. {@see Locale} is the second layer: a `lang` that
 * is not a well-formed language tag is dropped rather than passed along.
 */
final class Js extends Component
{
    public function __construct(
        public ?string $lang = null,
        public ?string $nonce = null,
    ) {}

    public function render(): View
    {
        $captcha = app(CaptchaService::class);
        $widget = $captcha->widget();

        return view('laranail-captcha::components.js', [
            'scriptUrl' => $widget->scriptUrl,
            'nonce' => $this->nonce,
            'lang' => Locale::sanitise($this->lang),
        ]);
    }
}
