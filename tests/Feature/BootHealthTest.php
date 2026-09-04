<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Simtabi\Laranail\Captcha\AdapterFactory;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Contracts\ReplayGuard;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Contracts\ChallengeStore;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;

/**
 * Asserts that a normal boot left nothing half-registered.
 *
 * A captcha package that silently fails to register its validation rule produces an application
 * that starts cleanly, logs nothing, and accepts every submission — no functional test catches
 * that, because every test that would notice is testing something else. So it is asserted
 * directly, and CI runs this file as its own job.
 */
it('registers every binding the package promises', function (string $abstract): void {
    expect(app()->bound($abstract))->toBeTrue();
})->with([
    CaptchaService::class,
    AdapterFactory::class,
    CredentialStore::class,
    ResolveCredentials::class,
    ReplayGuard::class,
    ChallengeStore::class,
    'captcha',
]);

it('merges the package configuration under the namespaced key', function (): void {
    expect(config('laranail.captcha.provider'))->toBe(Provider::Math->value)
        ->and(config('laranail.captcha.verification.max_age'))->toBe(300);
});

it('registers the captcha rule as implicit', function (): void {
    // The distinction this whole package turns on. A non-implicit rule is skipped when the field
    // is absent, so this assertion failing means a request that omits the captcha passes
    // validation — the exact bypass reported against the package this replaces.
    Validator::make([], ['captcha' => ['captcha']])->fails();

    $validator = Validator::make([], ['captcha' => ['captcha']]);

    expect($validator->fails())->toBeTrue();
});

it('resolves the configured adapter through the enum allow-list', function (): void {
    // The default provider needs no credentials, so a fresh install resolves a working adapter
    // rather than one that reports itself unconfigured.
    expect(app(CaptchaService::class)->provider())->toBe(Provider::Math)
        ->and(app(CaptchaService::class)->adapter()->provider())->toBe(Provider::Math)
        ->and(app(CaptchaService::class)->isConfigured())->toBeTrue();
});
