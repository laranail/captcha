<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * The checks that stand between "the vendor says this token is genuine" and "this submission is
 * legitimate".
 *
 * Every case below sends `success: true` from the provider. That is the point: each one is a
 * genuine, vendor-approved token that is nonetheless the wrong token for this request, and the
 * package this replaces accepted all of them.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.provider', 'turnstile');
    config()->set('laranail.captcha.environments.default.turnstile', [
        'site_key' => 'site-key',
        'secret'   => 'secret-key',
    ]);

    freezeClockAt('2026-08-12T12:00:00+00:00');
});

function freezeClockAt(string $moment): void
{
    app()->instance(ClockInterface::class, new readonly class($moment) implements ClockInterface
    {
        public function __construct(private string $moment) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->moment);
        }
    });
}

function captcha(): CaptchaService
{
    // Rebuilt per test because the policy is read from config once, when the service is
    // constructed — which is what keeps `config()` out of the verification path.
    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);

    return app(CaptchaService::class);
}

function turnstileAnswers(array $overrides = []): void
{
    Http::fake(fn () => Http::response([
        'success'      => true,
        'hostname'     => 'example.com',
        'challenge_ts' => '2026-08-12T11:59:30+00:00',
        ...$overrides,
    ], 200));
}

it('rejects a token solved on a host this application does not serve', function (): void {
    config()->set('laranail.captcha.verification.allowed_hostnames', ['example.com']);
    turnstileAnswers(['hostname' => 'attacker.test']);

    // The site key is public, so anyone can host the widget and collect genuine tokens. The
    // hostname is the only field that says where one was solved.
    $result = captcha()->verify('a-token');

    expect($result->passes())->toBeFalse()
        ->and($result->failedBecause(ErrorCode::HostnameMismatch))->toBeTrue();
});

it('accepts a token solved on an allowed host', function (): void {
    config()->set('laranail.captcha.verification.allowed_hostnames', ['example.com']);
    turnstileAnswers();

    expect(captcha()->verify('a-token')->passes())->toBeTrue();
});

it('does not compare hostnames when none are configured', function (): void {
    config()->set('laranail.captcha.verification.allowed_hostnames', []);
    turnstileAnswers(['hostname' => 'anywhere.test']);

    // Failing every request on an empty allow-list would make the safe default unusable, so the
    // gap is reported by the doctor command instead of enforced here.
    expect(captcha()->verify('a-token')->passes())->toBeTrue();
});

it('rejects a token minted for a different form', function (): void {
    turnstileAnswers(['action' => 'newsletter']);

    // A token from the newsletter signup, replayed on login. Both are genuine; only one belongs.
    $result = captcha()->verify('a-token', new VerificationContext(action: 'login'));

    expect($result->failedBecause(ErrorCode::ActionMismatch))->toBeTrue();
});

it('accepts a token minted for the form being protected', function (): void {
    turnstileAnswers(['action' => 'login']);

    expect(captcha()->verify('a-token', new VerificationContext(action: 'login'))->passes())->toBeTrue();
});

it('rejects a challenge solved longer ago than the window allows', function (): void {
    config()->set('laranail.captcha.verification.max_age', 300);
    turnstileAnswers(['challenge_ts' => '2026-08-12T10:00:00+00:00']);

    expect(captcha()->verify('a-token')->failedBecause(ErrorCode::Stale))->toBeTrue();
});

it('accepts a challenge solved within the window', function (): void {
    config()->set('laranail.captcha.verification.max_age', 300);
    turnstileAnswers();

    expect(captcha()->verify('a-token')->passes())->toBeTrue();
});

it('spends a token exactly once', function (): void {
    turnstileAnswers();

    $captcha = captcha();

    // The vendor burns the token too, but not before a second request carrying it can race the
    // first — and a self-hosted provider has no vendor to ask at all.
    expect($captcha->verify('the-same-token')->passes())->toBeTrue()
        ->and($captcha->verify('the-same-token')->failedBecause(ErrorCode::Replayed))->toBeTrue();
});

it('blocks a score below the review threshold', function (): void {
    config()->set('laranail.captcha.provider', 'recaptcha-v3');
    config()->set('laranail.captcha.environments.default.recaptcha-v3', [
        'site_key' => 'site-key', 'secret' => 'secret-key',
    ]);
    Http::fake(fn () => Http::response(['success' => true, 'score' => 0.1], 200));

    // The failure the old package could not express: a genuine token from a visitor Google
    // scored 0.1. `success` is true, and that is all it looked at.
    expect(captcha()->verify('a-token')->failedBecause(ErrorCode::LowScore))->toBeTrue();
});

it('marks a middling score for review rather than blocking it', function (): void {
    config()->set('laranail.captcha.provider', 'recaptcha-v3');
    config()->set('laranail.captcha.environments.default.recaptcha-v3', [
        'site_key' => 'site-key', 'secret' => 'secret-key',
    ]);
    Http::fake(fn () => Http::response(['success' => true, 'score' => 0.4], 200));

    $result = captcha()->verify('a-token');

    // Proceeds, but the host can read the outcome and ask for a second factor instead of
    // rejecting someone who is probably real.
    expect($result->passes())->toBeTrue()
        ->and($result->outcome)->toBe(Outcome::Review);
});

it('allows a high score outright', function (): void {
    config()->set('laranail.captcha.provider', 'recaptcha-v3');
    config()->set('laranail.captcha.environments.default.recaptcha-v3', [
        'site_key' => 'site-key', 'secret' => 'secret-key',
    ]);
    Http::fake(fn () => Http::response(['success' => true, 'score' => 0.9], 200));

    expect(captcha()->verify('a-token')->outcome)->toBe(Outcome::Allow);
});
