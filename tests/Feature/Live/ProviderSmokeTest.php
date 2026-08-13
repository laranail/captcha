<?php

declare(strict_types=1);

use Simtabi\Laranail\Captcha\AdapterFactory;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * The real endpoints, with the vendors' own published test keys.
 *
 * Excluded from the default suite because it needs the network — `vendor/bin/pest --group=live`.
 *
 * Everything else in the suite talks to `Http::fake()`, which means it verifies our handling of a
 * response *we wrote*. That proves the mapping and proves nothing about the endpoint URL, the
 * request encoding, or whether a vendor changed a field name. This file is the only thing that
 * would notice.
 *
 * The reCAPTCHA case turned out to prove something better than intended. Google's published test
 * keys verify *any* string — see the assertion at the bottom — which is the empirical justification
 * for refusing them in production, and is not visible from a faked response.
 */
function liveAdapter(Provider $provider, string $siteKey, string $secret): object
{
    config()->set('laranail.captcha.environments.default.' . $provider->value, [
        'site_key' => $siteKey,
        'secret' => $secret,
    ]);

    app()->forgetInstance(CredentialStore::class);

    return app(AdapterFactory::class)->make($provider);
}

it('verifies a Turnstile dummy token against the real endpoint', function (): void {
    $adapter = liveAdapter(
        Provider::Turnstile,
        '1x00000000000000000000AA',
        '1x0000000000000000000000000000000AA',
    );

    $result = $adapter->verify('XXXX.DUMMY.TOKEN.XXXX', VerificationContext::none());

    expect($result->verified)->toBeTrue();
})->group('live');

it('is refused by the Turnstile always-fail secret', function (): void {
    $adapter = liveAdapter(
        Provider::Turnstile,
        '2x00000000000000000000AB',
        '2x0000000000000000000000000000000AA',
    );

    // The always-fail keys are the only easy way to exercise a real rejection end to end, rather
    // than a rejection we synthesised.
    expect($adapter->verify('XXXX.DUMMY.TOKEN.XXXX', VerificationContext::none())->verified)->toBeFalse();
})->group('live');

it('maps Turnstile’s already-spent response onto the expired code', function (): void {
    $adapter = liveAdapter(
        Provider::Turnstile,
        '1x00000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    );

    // Cloudflare answers `timeout-or-duplicate` for this secret. If they rename it, our mapping
    // silently degrades to a generic provider error and every replay stops being identifiable.
    expect($adapter->verify('XXXX.DUMMY.TOKEN.XXXX', VerificationContext::none())
        ->failedBecause(ErrorCode::ExpiredOrDuplicate))->toBeTrue();
})->group('live');

it('verifies an hCaptcha test token against the documented host', function (): void {
    $adapter = liveAdapter(
        Provider::HCaptcha,
        '10000000-ffff-ffff-ffff-000000000001',
        '0x0000000000000000000000000000000000000000',
    );

    // Also the only check that `api.hcaptcha.com` is the host that answers. The package this
    // replaced posted to `hcaptcha.com`, which works by redirect until it does not.
    expect($adapter->verify('10000000-aaaa-bbbb-cccc-000000000001', VerificationContext::none())->verified)
        ->toBeTrue();
})->group('live');

it('shows why the reCAPTCHA test keys are refused in production', function (): void {
    $adapter = liveAdapter(
        Provider::ReCaptchaV2,
        '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI',
        '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
    );

    $result = $adapter->verify('not-a-real-token', VerificationContext::none());

    // Google's published test keys verify *anything*. The literal string "not-a-real-token" comes
    // back `success: true`, from `testkey.google.com`. No faked response could have shown this, and
    // it is the whole justification for GuardProductionSafety refusing a production request whose
    // credentials resolved from the test-key store: an application in that state looks perfectly
    // healthy and accepts every submission.
    expect($result->verified)->toBeTrue()
        ->and($result->hostname)->toBe('testkey.google.com');
})->group('live');
