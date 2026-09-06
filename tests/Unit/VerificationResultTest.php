<?php

declare(strict_types=1);

use Simtabi\Laranail\Captcha\Enums\Outcome;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;

it('treats a passed result as passing validation', function (): void {
    expect(VerificationResult::passed()->passes())->toBeTrue();
});

it('treats a review outcome as passing validation but not as an unqualified allow', function (): void {
    $result = VerificationResult::passed()->withOutcome(Outcome::Review);

    // The middle band of a risk score: good enough to proceed, not good enough to proceed
    // silently. Collapsing it to a rejection is what throws away the signal v3 exists to give.
    expect($result->passes())->toBeTrue()
        ->and($result->outcome)->toBe(Outcome::Review);
});

it('never passes once an error is attached', function (): void {
    $result = VerificationResult::passed()->withError(ErrorCode::Replayed);

    expect($result->passes())->toBeFalse()
        ->and($result->verified)->toBeFalse()
        ->and($result->failedBecause(ErrorCode::Replayed))->toBeTrue();
});

it('keeps the provider fields when a post-verification check fails', function (): void {
    $result = VerificationResult::passed(score: 0.9, action: 'login', hostname: 'example.com')
        ->withError(ErrorCode::HostnameMismatch);

    // The reason a check failed is only diagnosable if the values it compared survive into the
    // event and the log.
    expect($result->score)->toBe(0.9)
        ->and($result->action)->toBe('login')
        ->and($result->hostname)->toBe('example.com');
});

it('separates operator faults from suspicious visitors', function (): void {
    expect(VerificationResult::failed(ErrorCode::InvalidSecret)->isOperatorFault())->toBeTrue()
        ->and(VerificationResult::failed(ErrorCode::LowScore)->isOperatorFault())->toBeFalse();
});

it('redacts the secret when credentials are dumped', function (): void {
    $credentials = new Credentials('site-key', 'super-secret', CredentialSource::Config);

    $dumped = print_r($credentials, true);

    // dd(), a Whoops frame and a failed_jobs payload all reach for this. The site key is public;
    // the secret is the one value that must never survive the trip.
    expect($dumped)->toContain('site-key')
        ->and($dumped)->not->toContain('super-secret')
        ->and($dumped)->toContain('[redacted]');
});
