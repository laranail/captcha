<?php

declare(strict_types=1);

use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Facades\Captcha;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Testing\VerificationAttempt;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;

/**
 * The helpers a host application's tests use.
 *
 * `Captcha::fake()` existed already; these are the assertions that make it worth having — counting
 * calls proves a captcha ran, not that the right submission was checked.
 */
it('keeps returning the service so existing chains still work', function (): void {
    // The signature is `self` and the facade advertises it. Returning the double instead would be
    // a silent breaking change for anyone chaining off `fake()`.
    expect(Captcha::fake())->toBeInstanceOf(CaptchaService::class);
});

it('records what was verified, not merely that something was', function (): void {
    Captcha::fake();

    Captcha::verify('the-token');

    Captcha::assertVerified(fn (VerificationAttempt $a): bool => $a->token === 'the-token');
});

it('reports a failed verification separately from a passing one', function (): void {
    Captcha::fake(verifies: false);

    Captcha::verify('a-token');

    Captcha::assertFailed();
});

it('asserts nothing was verified', function (): void {
    Captcha::fake();

    Captcha::assertNothingVerified();
});

it('counts verifications', function (): void {
    Captcha::fake();

    Captcha::verify('one');
    Captcha::verify('two');

    Captcha::assertVerifiedCount(2);
});

it('fakes a score so the review band can be exercised', function (): void {
    Captcha::fakeScore(0.4);

    // Between the review and allow thresholds: passes validation, but the host can step up.
    expect(Captcha::verify('a-token')->outcome)->toBe(Outcome::Review);
});

it('answers a scripted sequence one result per call', function (): void {
    Captcha::fakeSequence([
        VerificationResult::failed(ErrorCode::InvalidResponse),
        VerificationResult::passed(),
    ]);

    expect(Captcha::verify('first')->passes())->toBeFalse()
        ->and(Captcha::verify('second')->passes())->toBeTrue();
});

it('explains what was forgotten when asserting without a fake', function (): void {
    expect(fn () => Captcha::assertVerified())
        ->toThrow(RuntimeException::class, 'Call Captcha::fake()');
});

it('still fails closed if a fake is left in production code', function (): void {
    // Environment first: the guard reads it when the service is built, so faking before the
    // switch would test a service that still believes it is in `testing`.
    app()->detectEnvironment(fn (): string => 'production');
    app()->forgetInstance(ResolveCredentials::class);
    app()->forgetInstance(CaptchaService::class);

    Captcha::fake();

    // The fake reports itself as the null provider precisely so the production guard refuses it.
    // A fake that reported a real provider would quietly accept every submission.
    expect(Captcha::verify('a-token')->passes())->toBeFalse();
});
