<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Services\CaptchaService;

/**
 * The two ways a production deployment can end up accepting every submission.
 *
 * Both look completely healthy from the outside: the application boots, the config validates,
 * verification returns success, nothing is logged. The only symptom is that the captcha stopped
 * working, and nobody notices until the spam arrives.
 */
function inProduction(string $environment = 'production'): void
{
    app()->detectEnvironment(fn (): string => $environment);

    app()->forgetInstance(CredentialStore::class);
    app()->forgetInstance(ResolveCredentials::class);
    app()->forgetInstance(GuardProductionSafety::class);
    app()->forgetInstance(CaptchaService::class);
}

it('refuses the null provider in production', function (): void {
    config()->set('laranail.captcha.provider', 'null');
    inProduction();

    $result = app(CaptchaService::class)->verify('any-token');

    // Fails closed. A misconfiguration blocking submissions is recoverable; one accepting them
    // silently is not.
    expect($result->passes())->toBeFalse()
        ->and($result->failedBecause(ErrorCode::NotConfigured))->toBeTrue();
});

it('allows the null provider in production only when written down', function (): void {
    config()->set('laranail.captcha.provider', 'null');
    config()->set('laranail.captcha.providers.null.allow_in_production', true);
    inProduction();

    // The escape hatch exists because there are legitimate uses — a staging environment sharing
    // the production APP_ENV. The point is that it cannot happen by accident.
    expect(app(CaptchaService::class)->verify('any-token')->passes())->toBeTrue();
});

it('refuses the published test keys in production', function (): void {
    config()->set('laranail.captcha.provider', 'turnstile');
    config()->set('laranail.captcha.environments.default', []);
    config()->set('laranail.captcha.credentials.test_keys.enabled', true);
    config()->set('laranail.captcha.credentials.test_keys.environments', ['local', 'testing', 'production']);
    inProduction();

    Http::fake(fn () => Http::response(['success' => true], 200));

    // Even with an operator having listed production as a test-key environment, which is the
    // mistake this guard exists for. The store would happily serve keys that verify anything.
    expect(app(CaptchaService::class)->verify('any-token')->passes())->toBeFalse();

    Http::assertNothingSent();
});

it('logs why it refused, without putting it on the page', function (): void {
    Log::spy();

    config()->set('laranail.captcha.provider', 'null');
    inProduction();

    app(CaptchaService::class)->verify('any-token');

    // The message names the fix, which makes it useful in a log and wrong to render to a
    // visitor. Letting the exception reach the handler would do both.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'null')
            && str_contains($message, 'allow_in_production'));
});

it('treats a deployment name that is not production as not production', function (): void {
    config()->set('laranail.captcha.provider', 'null');
    inProduction('staging');

    expect(app(CaptchaService::class)->verify('any-token')->passes())->toBeTrue();
});

it('catches a production deployment reporting an unconventional environment name', function (): void {
    config()->set('laranail.captcha.provider', 'null');
    config()->set('laranail.captcha.production_environments', ['production', 'prod', 'codecanyon']);
    inProduction('codecanyon');

    // APP_ENV is a deployment name, and some products ship it as a feature flag — Worksuite
    // reports `codecanyon` on live installations. A name in neither list would sail past a check
    // that only asked the container, so the list is configurable.
    expect(app(CaptchaService::class)->verify('any-token')->passes())->toBeFalse();
});
