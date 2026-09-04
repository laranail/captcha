<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Simtabi\Laranail\Captcha\Services\CaptchaService;

/**
 * `<x-captcha-container />` — where the active provider's widget renders.
 *
 * Each instance gets its own generated id, so two forms on one page work. The old implementation
 * had no ids and its callback reached for `document.querySelector('.cf-turnstile')`, which finds
 * the first widget on the page regardless of which form is being submitted.
 */
final class Container extends Component
{
    public Widget $widget;

    public function __construct(
        public ?string $theme = null,
        public ?string $size = null,
        public ?string $id = null,
    ) {
        $this->widget = app(CaptchaService::class)->widget($this->id);
    }

    public function render(): View
    {
        $attributes = $this->widget->attributes();

        // Per-instance overrides win over the configured defaults, so one form can be compact
        // without changing the widget config for the whole application.
        if ($this->theme !== null) {
            $attributes['data-theme'] = $this->theme;
        }

        if ($this->size !== null) {
            $attributes['data-size'] = $this->size;
        }

        return view('laranail-captcha::components.container', [
            'widget'           => $this->widget,
            'widgetAttributes' => $attributes,
        ]);
    }
}
