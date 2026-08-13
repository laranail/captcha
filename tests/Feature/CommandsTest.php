<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Models\CaptchaSetting;

/**
 * The commands, actually run.
 *
 * Registering a command proves nothing — it resolves, its dependencies inject, and then the first
 * person to type it finds out whether the body works. These run each one.
 */
it('reports a healthy default install as having no problems', function (): void {
    config()->set('laranail.captcha.verification.allowed_hostnames', ['example.com']);

    $this->artisan('laranail::captcha.doctor')
        ->expectsOutputToContain('math')
        ->assertSuccessful();
});

it('fails the doctor when production would accept every submission', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('laranail.captcha.provider', 'turnstile');
    config()->set('laranail.captcha.environments.default', []);
    config()->set('laranail.captcha.credentials.test_keys.enabled', true);
    config()->set('laranail.captcha.credentials.test_keys.environments', ['production']);
    config()->set('laranail.captcha.verification.allowed_hostnames', ['example.com']);

    app()->forgetInstance(CredentialStore::class);

    // A non-zero exit is what makes this usable as a deploy gate, which is the only way a check
    // like this gets run at the moment it matters.
    $this->artisan('laranail::captcha.doctor')->assertFailed();
});

it('warns when hostname enforcement is on with nothing to compare against', function (): void {
    config()->set('laranail.captcha.verification.enforce_hostname', true);
    config()->set('laranail.captcha.verification.allowed_hostnames', []);

    $this->artisan('laranail::captcha.doctor')
        ->expectsOutputToContain('no hostnames are listed')
        ->assertFailed();
});

it('shows where every provider resolves its credentials from', function (): void {
    config()->set('laranail.captcha.environments.default.turnstile', [
        'site_key' => 'site-key-abcdef',
        'secret' => 'a-secret',
    ]);
    app()->forgetInstance(CredentialStore::class);

    $this->artisan('laranail::captcha.keys')
        ->expectsOutputToContain('turnstile')
        ->assertSuccessful();
});

it('never prints a secret', function (): void {
    config()->set('laranail.captcha.environments.default.turnstile', [
        'site_key' => 'site-key-abcdef',
        'secret' => 'the-actual-secret',
    ]);
    app()->forgetInstance(CredentialStore::class);

    $this->artisan('laranail::captcha.keys')->assertSuccessful();

    expect(app(Kernel::class)->output())->not->toContain('the-actual-secret');
});

it('publishes the configuration', function (): void {
    $this->artisan('laranail::captcha.install')->assertSuccessful();
});

it('says nothing to do when credential caching is off', function (): void {
    config()->set('laranail.captcha.credentials.database.cache.enabled', false);

    $this->artisan('laranail::captcha.cache-clear')
        ->expectsOutputToContain('nothing to clear')
        ->assertSuccessful();
});

it('forgets cached credentials so a database change applies immediately', function (): void {
    config()->set('laranail.captcha.credentials.database.enabled', true);
    config()->set('laranail.captcha.credentials.database.cache.enabled', true);
    app()->forgetInstance(CredentialStore::class);

    CaptchaSetting::query()->create([
        'provider' => 'turnstile', 'environment' => 'testing', 'key' => 'site_key', 'value' => 'first',
    ]);
    CaptchaSetting::query()->create([
        'provider' => 'turnstile', 'environment' => 'testing', 'key' => 'secret', 'value' => 'first-secret',
    ]);

    $store = app(CredentialStore::class);

    expect($store->get(Provider::Turnstile, 'testing')?->siteKey)->toBe('first');

    // Through the model, so the encrypted cast writes what the cast will later read. A raw
    // builder update with encrypt() serialises, and decryptString does not unserialise.
    CaptchaSetting::query()->where('key', 'site_key')->firstOrFail()->update(['value' => 'second']);

    // Still the cached value — which is the whole reason this command exists.
    expect($store->get(Provider::Turnstile, 'testing')?->siteKey)->toBe('first');

    $this->artisan('laranail::captcha.cache-clear', ['--environment' => 'testing'])->assertSuccessful();

    expect(app(CredentialStore::class)->get(Provider::Turnstile, 'testing')?->siteKey)
        ->toBe('second');
});
