<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\View;

use Simtabi\Laranail\Captcha\Support\DeprecationNotices;

/**
 * A Blade precompiler that announces the bare `<x-captcha />`, `<x-captcha-js />` and
 * `<x-captcha-container />` tags, once each per process, and returns the template unchanged.
 *
 * The tags still render: they remain registered as aliases of the same component classes. They are
 * deprecated in favour of `<x-laranail-captcha::captcha />`, `::js` and `::container`, and removed no
 * earlier than the next minor after 0.1.
 */
final class DeprecatedComponentTags
{
    /** bare tag => scoped tag */
    public const array TAGS = [
        'captcha'           => 'laranail-captcha::captcha',
        'captcha-js'        => 'laranail-captcha::js',
        'captcha-container' => 'laranail-captcha::container',
    ];

    public function __invoke(string $template): string
    {
        // Precompilers run after Blade has compiled component tags, so the tag is read back from the
        // name the compiled component carries: `@component('…\Js', 'captcha-js', [...])`.
        if (! str_contains($template, "', 'captcha")) {
            return $template;
        }

        if (preg_match_all("/@component\\('[^']+', '(captcha(?:-js|-container)?)'/", $template, $matches) > 0) {
            foreach (array_unique($matches[1]) as $tag) {
                DeprecationNotices::once('tag:' . $tag, sprintf(
                    'laranail/captcha: the <x-%s /> component tag is deprecated and will be removed no earlier than the next minor after 0.1; use <x-%s />.',
                    $tag,
                    self::TAGS[$tag],
                ));
            }
        }

        return $template;
    }
}
