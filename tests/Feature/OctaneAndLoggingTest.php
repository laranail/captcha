<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Facades\Captcha;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Support\CaptchaConfig;

/**
 * The two things that only go wrong on someone else's infrastructure.
 *
 * Octane keeps singletons alive between requests, so a credential rotated in the database stays
 * invisible on a warm worker — the admin UI saves, nothing changes, and nothing says why. Logging
 * is the other half: without it a score threshold is a guess.
 */
it('listens on both Octane boundaries', function (string $event): void {
    // By event *name*, so no `class_exists` probe and no dependency — without Octane these are
    // simply never dispatched. Both boundaries, because a request that dies hard never reaches
    // termination.
    expect(app(Dispatcher::class)->hasListeners($event))->toBeTrue();
})->with([
    'Laravel\Octane\Events\RequestReceived',
    'Laravel\Octane\Events\RequestTerminated',
]);

it('forgets request-scoped state when the boundary fires', function (): void {
    $before = app(CaptchaService::class);

    app(Dispatcher::class)->dispatch('Laravel\Octane\Events\RequestTerminated');

    // A new instance, so the next request re-resolves credentials rather than serving whatever
    // the worker cached hours ago.
    expect(app(CaptchaService::class))->not->toBe($before);
});

it('does not build a singleton merely to reset it', function (): void {
    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
    app()->forgetInstance(ResolveCredentials::class);

    app(Dispatcher::class)->dispatch('Laravel\Octane\Events\RequestReceived');

    // Resetting by resolving would construct the whole credential chain — including a database
    // probe — at every request boundary, on every request that never touched a captcha.
    expect(app()->resolved(CaptchaService::class))->toBeFalse();
});

it('keeps the config memo warm across the boundary', function (): void {
    $config = app(CaptchaConfig::class);

    app(Dispatcher::class)->dispatch('Laravel\Octane\Events\RequestTerminated');

    // A memo of config reads is exactly what should survive in a long-running process; flushing
    // it would cost every request for no correctness gain.
    expect(app(CaptchaConfig::class))->toBe($config);
});

it('logs nothing unless asked', function (): void {
    Log::spy();
    Captcha::fake(verifies: false);

    Captcha::verify('a-token');

    // Ordinary bot traffic at volume. A line per rejection turns a flood into a disk-space
    // incident, so this is opt-in.
    Log::shouldNotHaveReceived('log');
});

it('never writes the token to a log line', function (): void {
    config()->set('laranail.captcha.logging.enabled', true);
    $this->refreshApplication();

    Log::spy();
    Captcha::fake(verifies: false);

    Captcha::verify('a-very-secret-looking-token');

    // The token is a live credential until it is spent, and a log aggregator is a far softer
    // target than the session it protects.
    Log::shouldNotHaveReceived('log', fn (string $level, string $message, array $context): bool => str_contains(json_encode($context) . $message, 'a-very-secret-looking-token'));
});
