<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Models\CaptchaSetting;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;

/**
 * The resolution order the package promises: database, then config, then test keys.
 *
 * The database half has to hold under conditions a credential lookup is guaranteed to meet — a
 * fresh clone before `migrate`, a CI container with no database — without throwing and without
 * writing to the log on every request.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.credentials.database.enabled', true);
    config()->set('laranail.captcha.environments.default.turnstile', [
        'site_key' => 'config-site-key',
        'secret'   => 'config-secret',
    ]);

    app()->forgetInstance(CredentialStore::class);
});

function setting(string $environment, string $key, string $value): void
{
    CaptchaSetting::query()->create([
        'provider'    => 'turnstile',
        'environment' => $environment,
        'key'         => $key,
        'value'       => $value,
    ]);
}

it('prefers a database row over the configured key', function (): void {
    // Written through the model so the encrypted cast round-trips exactly as it will in an
    // application. A raw builder insert would bypass the cast and prove nothing about the read.
    setting('testing', 'site_key', 'db-site-key');
    setting('testing', 'secret', 'db-secret');

    $credentials = app(CredentialStore::class)->get(Provider::Turnstile, 'testing');

    // An operator changing a key in an admin UI has to beat the .env the application booted with,
    // or the admin UI is decorative.
    expect($credentials?->siteKey)->toBe('db-site-key')
        ->and($credentials?->secret)->toBe('db-secret')
        ->and($credentials?->source)->toBe(CredentialSource::Database);
});

it('falls through to config when the database has no row', function (): void {
    $credentials = app(CredentialStore::class)->get(Provider::Turnstile, 'testing');

    expect($credentials?->siteKey)->toBe('config-site-key')
        ->and($credentials?->source)->toBe(CredentialSource::Config);
});

it('scopes rows to the environment they were written for', function (): void {
    setting('production', 'site_key', 'prod-site-key');
    setting('production', 'secret', 'prod-secret');

    // A production row must not answer for a staging lookup. This is the whole reason the
    // environment column is not nullable.
    expect(app(CredentialStore::class)->get(Provider::Turnstile, 'testing')?->source)
        ->toBe(CredentialSource::Config)
        ->and(app(CredentialStore::class)->get(Provider::Turnstile, 'production')?->siteKey)
        ->toBe('prod-site-key');
});

it('falls through to config when the table does not exist', function (): void {
    Schema::drop('captcha_settings');

    // The fresh-clone case: composer install, no migrate yet. A bare query here throws and takes
    // the login page with it; the db-tools guard answers "no" and the chain moves on.
    $credentials = app(CredentialStore::class)->get(Provider::Turnstile, 'testing');

    expect($credentials?->siteKey)->toBe('config-site-key')
        ->and($credentials?->source)->toBe(CredentialSource::Config);
});

it('treats an absent row as deliberate when told to', function (): void {
    config()->set('laranail.captcha.credentials.database.row_absent_means', 'disabled');
    app()->forgetInstance(CredentialStore::class);

    // An operator who deletes a row to turn a provider off should not find it still working,
    // quietly served by a secret still sitting in .env.
    $credentials = app(CredentialStore::class)->get(Provider::Turnstile, 'testing');

    expect($credentials?->isComplete())->toBeFalse()
        ->and($credentials?->source)->toBe(CredentialSource::None);
});

it('survives a value written under a different application key', function (): void {
    CaptchaSetting::query()->insert([
        ['provider' => 'turnstile', 'environment' => 'testing', 'key' => 'site_key', 'value' => 'not-valid-ciphertext'],
        ['provider' => 'turnstile', 'environment' => 'testing', 'key' => 'secret', 'value' => 'not-valid-ciphertext'],
    ]);

    // An APP_KEY rotation must not turn every login into a 500 — the fix for it is a migration
    // that cannot be run through an application which will not boot.
    $credentials = app(CredentialStore::class)->get(Provider::Turnstile, 'testing');

    expect($credentials?->source)->toBe(CredentialSource::Config);
});

it('serves the published test keys only where they are allowed', function (): void {
    config()->set('laranail.captcha.credentials.test_keys.enabled', true);
    config()->set('laranail.captcha.environments.default.turnstile', []);
    app()->forgetInstance(CredentialStore::class);

    expect(app(CredentialStore::class)->get(Provider::Turnstile, 'testing')?->source)
        ->toBe(CredentialSource::TestKeys)
        ->and(app(CredentialStore::class)->get(Provider::Turnstile, 'production'))
        ->toBeNull();
});
