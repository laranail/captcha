<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Captcha\Support\Locale;

/**
 * The template-injection vector in the package this replaces.
 *
 * Its `Js` component returned the provider's script tag as a *string*. A Blade component whose
 * `render()` returns a string has that string written to disk and compiled as a template
 * (`Illuminate\View\Component::createBladeViewFromString`), and the locale was interpolated into
 * it unescaped — so a caller passing user input to `lang` got HTML injection into the script tag,
 * Blade injection, and one compiled view file written per distinct input.
 *
 * Two layers are asserted: the locale filter, and the component returning a view.
 */
it('drops a locale that is not a language tag', function (string $hostile): void {
    expect(Locale::sanitise($hostile))->toBeNull();
})->with([
    'blade echo'          => '{{ 7*7 }}',
    'blade raw echo'      => '{!! 7*7 !!}',
    'blade directive'     => '@php echo 49; @endphp',
    'attribute break-out' => '" onload="alert(1)',
    'tag break-out'       => '"></script><script>alert(1)</script>',
    'path traversal'      => '../../../../etc/passwd',
]);

it('strips a trailing null byte rather than carrying it into a URL', function (): void {
    // A null byte truncates the string for anything that hands it to a C-level API. It is removed
    // rather than rejected, because the tag either side of it is still a legitimate locale.
    expect(Locale::sanitise("en\0"))->toBe('en');
});

it('keeps a well-formed language tag', function (string $locale): void {
    expect(Locale::sanitise($locale))->toBe($locale);
})->with(['en', 'fr', 'en-GB', 'zh-Hant-TW', 'pt-BR']);

it('never evaluates a hostile locale as blade', function (): void {
    $rendered = Blade::render('<x-captcha-js :lang="$lang" />', ['lang' => '{{ 7*7 }}']);

    // 49 anywhere in the output would mean the injected expression was compiled and run.
    expect($rendered)->not->toContain('49')
        ->and($rendered)->not->toContain('7*7');
});

it('escapes a hostile widget attribute rather than evaluating it', function (): void {
    $rendered = Blade::render('<x-captcha-container :theme="$theme" />', ['theme' => '{{ 7*7 }}']);

    expect($rendered)->not->toContain('49');
});
