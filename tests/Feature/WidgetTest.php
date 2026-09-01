<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Services\CaptchaService;

function useProvider(string $provider): void
{
    config()->set('laranail.captcha.provider', $provider);
    config()->set("laranail.captcha.environments.default.{$provider}", [
        'site_key' => 'site-key-abc',
        'secret' => 'secret-key',
    ]);

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
}

it('gives each widget on a page its own id', function (): void {
    useProvider('turnstile');

    preg_match_all('/id="(captcha-[a-z0-9]+)"/', Blade::render('<x-captcha /><x-captcha />'), $matches);

    // The original had no ids and its callback reached for the first `.cf-turnstile` on the page,
    // so a second form submitted the first form's token.
    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->not->toBe($matches[1][1]);
});

it('wires execution on submit for providers with nothing to click', function (string $provider): void {
    useProvider($provider);

    $rendered = Blade::render('<form><x-captcha /></form>');

    // Without this these providers render an empty div and the form submits with no captcha —
    // listed as supported, and not working.
    expect($rendered)->toContain('&quot;execute&quot;:true')
        ->toContain('name="captcha"')
        ->toContain('grecaptcha.execute');
})->with(['recaptcha-v3', 'recaptcha-v2-invisible']);

it('does not wire execution for providers that render a challenge', function (string $provider): void {
    useProvider($provider);

    $rendered = Blade::render('<form><x-captcha /></form>');

    // Asserted on the container's own configuration, not on the absence of a string. The runtime
    // is shared and emitted once per page, so `grecaptcha.execute` is present regardless — inert
    // unless a container asks for it. Asserting its absence would assert a fact about the bundle
    // rather than about this provider, which is how this test read before the runtime was shared.
    expect($rendered)->toContain('&quot;execute&quot;:false')
        ->not->toContain('-token"');
})->with(['turnstile', 'hcaptcha', 'recaptcha-v2']);

it('escapes values that reach the executing script', function (): void {
    $hostile = '"); alert(1); ("';

    useProvider('recaptcha-v3');
    config()->set('laranail.captcha.providers.recaptcha.action', $hostile);
    app()->forgetInstance(CaptchaService::class);

    $rendered = Blade::render('<form><x-captcha /></form>');

    // The action now travels in the container's JSON config rather than interpolated into the
    // script body, so the escaping that matters is HTML-attribute escaping. Either way the payload
    // must not survive as something that could terminate a string or an attribute.
    expect($rendered)->not->toContain($hostile)
        ->and($rendered)->toContain('&quot;); alert(1); (&quot;');
});

it('carries a nonce onto every script it emits', function (): void {
    useProvider('recaptcha-v3');

    $rendered = Blade::render('<form><x-captcha nonce="abc123" /></form>');

    // Two scripts for this provider: the vendor's, and the shared runtime. A strict CSP needs
    // both, and missing one is indistinguishable from the captcha simply not working.
    expect(substr_count($rendered, 'nonce="abc123"'))->toBe(2);
});

it('renders a self-hosted question that works without JavaScript', function (): void {
    useProvider('math');

    $rendered = Blade::render('<form><x-captcha /></form>');

    // The runtime script is emitted — it recovers an expired question — but nothing in the markup
    // depends on it. No vendor script is loaded, and the question, answer box and signed challenge
    // are all present and submittable with scripting off.
    expect($rendered)->toContain('name="captcha_answer"')
        ->toContain('name="captcha_challenge"')
        ->toContain('laranail-captcha-question')
        ->not->toContain('<script src');
});

it('associates the question with its input for screen readers', function (): void {
    useProvider('math');

    $rendered = Blade::render('<x-captcha />');

    preg_match('/aria-describedby="([^"]+)"/', $rendered, $describedBy);
    preg_match('/class="laranail-captcha-question" id="([^"]+)"/', $rendered, $question);

    expect($describedBy[1] ?? 'a')->toBe($question[1] ?? 'b');
});

it('emits the expiry callbacks the vendor widgets call', function (): void {
    useProvider('turnstile');

    $rendered = Blade::render('<x-captcha />');

    // A Turnstile token dies at 300 seconds. Without these, a form left open past that submits a
    // dead token and fails for a reason the visitor cannot act on.
    expect($rendered)->toContain('data-expired-callback="laranailCaptchaExpired"')
        ->toContain('data-timeout-callback="laranailCaptchaExpired"')
        ->toContain('data-error-callback="laranailCaptchaExpired"')
        ->toContain('turnstile.reset');
});

it('emits the runtime once however many widgets are on the page', function (): void {
    useProvider('turnstile');

    // `@once` tracks what it has already emitted on the view factory, and that state survives
    // between renders in one process — so an earlier test in this file would otherwise consume it
    // and leave this asserting zero. Laravel clears it per request in production.
    app('view')->flushState();

    $rendered = Blade::render('<x-captcha /><x-captcha /><x-captcha />');

    // Two copies would install two MutationObservers and two morph hooks, each processing the
    // other's containers.
    expect(substr_count($rendered, 'MutationObserver'))->toBe(1)
        ->and(substr_count($rendered, 'data-captcha-config="'))->toBe(3);
});

it('skips the morph only for providers holding live vendor state', function (): void {
    useProvider('turnstile');

    // A vendor widget is a live iframe holding a session; letting a morph replace it discards an
    // already-solved challenge with nothing visible but a form that stops working.
    expect(Blade::render('<x-captcha />'))->toContain('&quot;skipMorph&quot;:true');

    useProvider('math');

    // The opposite for a server-rendered question: a re-render is how a fresh one arrives, so
    // skipping would pin an expired question on screen.
    expect(Blade::render('<x-captcha />'))->toContain('&quot;skipMorph&quot;:false');
});

it('marks every provider that needs execution as needing it', function (): void {
    $needing = array_values(array_filter(
        Provider::cases(),
        static fn (Provider $p): bool => $p->requiresExplicitExecution(),
    ));

    // A new score-only provider added without this flag would render a dead container, so the set
    // is asserted rather than assumed.
    expect($needing)->toBe([Provider::ReCaptchaV2Invisible, Provider::ReCaptchaV3]);
});

it('knows how to reset the widgets whose reset API is documented', function (Provider $provider): void {
    expect($provider->resetFunction())->not->toBeNull();
})->with([
    'turnstile' => [Provider::Turnstile],
    'hcaptcha' => [Provider::HCaptcha],
    'recaptcha v2' => [Provider::ReCaptchaV2],
    'recaptcha v2 invisible' => [Provider::ReCaptchaV2Invisible],
    'recaptcha v3' => [Provider::ReCaptchaV3],
    'recaptcha enterprise' => [Provider::ReCaptchaEnterprise],
]);

it('ships no default reset for the providers whose reset is not a global', function (Provider $provider): void {
    // Both render a vendor widget, and both *do* have a reset — but on an object the application
    // holds, not on a global: Friendly Captcha's is a WidgetHandle method, Arkose's is on the
    // enforcement instance passed to the app's own setup callback. A guessed global name would
    // resolve to nothing and silently not reset, so the package ships none and offers
    // `providers.*.reset_function` instead.
    expect($provider->resetFunction())->toBeNull();
})->with([
    'friendly captcha' => [Provider::FriendlyCaptcha],
    'arkose' => [Provider::Arkose],
]);

it('lets an application name its own reset for those providers', function (): void {
    useProvider('friendly-captcha');
    config()->set('laranail.captcha.providers.friendly-captcha.reset_function', 'resetFriendlyCaptcha');

    // The seam that turns "cannot be done here" into "done where the handle actually lives".
    expect(Blade::render('<x-captcha />'))->toContain('resetFriendlyCaptcha');
});
