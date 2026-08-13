<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\ValueObjects\Challenge;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;

/**
 * Self-hosted proof-of-work: no vendor, no round trip, and therefore no vendor doing any of the
 * bookkeeping for us.
 *
 * Three things carry the security here, and this file exists to prove each one, because getting
 * any of them subtly wrong leaves a scheme that still looks like it works.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.provider', 'altcha');
    config()->set('laranail.captcha.providers.altcha.hmac_key', 'a-server-held-key');

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
});

/** Do the work a browser would: count until the hash matches. */
function solve(Challenge $challenge, ?int $number = null): string
{
    $number ??= (function () use ($challenge): int {
        for ($candidate = 0; $candidate <= $challenge->maxNumber; $candidate++) {
            if (hash('sha256', $challenge->salt . $candidate) === $challenge->challenge) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unsolvable challenge.');
    })();

    return base64_encode((string) json_encode([
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'number' => $number,
        'salt' => $challenge->salt,
        'signature' => $challenge->signature,
    ]));
}

it('verifies a challenge it minted and a browser solved', function (): void {
    $captcha = app(CaptchaService::class);

    $challenge = $captcha->issueChallenge();

    expect($challenge)->not->toBeNull()
        ->and($captcha->verify(solve($challenge))->passes())->toBeTrue();
});

it('spends a solved challenge exactly once', function (): void {
    $captcha = app(CaptchaService::class);
    $payload = solve($captcha->issueChallenge());

    // The maths still checks out on the tenth replay. Without redemption tracking, one solved
    // challenge is a reusable pass — and this is the guarantee the hosted providers give away
    // for free and a self-hosted scheme has to earn.
    expect($captcha->verify($payload)->passes())->toBeTrue()
        ->and($captcha->verify($payload)->passes())->toBeFalse();
});

it('rejects a challenge whose number does not produce the published hash', function (): void {
    $captcha = app(CaptchaService::class);
    $challenge = $captcha->issueChallenge();

    expect($captcha->verify(solve($challenge, number: 999_999))->failedBecause(ErrorCode::InvalidResponse))
        ->toBeTrue();
});

it('rejects a challenge this server never signed', function (): void {
    $captcha = app(CaptchaService::class);

    // A client inventing its own trivially easy challenge and solving that instead. The signature
    // is the only thing standing between the scheme and this, which is why it is compared with
    // hash_equals rather than ===.
    $forged = new Challenge(
        algorithm: 'SHA-256',
        challenge: hash('sha256', 'forged-salt0'),
        salt: 'forged-salt?expires=' . (time() + 300),
        signature: hash_hmac('sha256', hash('sha256', 'forged-salt0'), 'the-wrong-key'),
        maxNumber: 10,
        expiresAt: new DateTimeImmutable('+5 minutes'),
    );

    expect($captcha->verify(solve($forged, number: 0))->passes())->toBeFalse();
});

it('rejects a challenge that has expired', function (): void {
    $captcha = app(CaptchaService::class);
    $payload = solve($captcha->issueChallenge());

    // The expiry rides inside the salt so the HMAC covers it. Sent as a separate field it would
    // be client-editable, and a challenge whose expiry can be rewritten never expires.
    app()->instance(ClockInterface::class, new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('+1 hour');
        }
    });

    app()->forgetInstance(CaptchaService::class);

    expect(app(CaptchaService::class)->verify($payload)->failedBecause(ErrorCode::Stale))->toBeTrue();
});

it('rejects a payload that is not a challenge at all', function (string $payload): void {
    expect(app(CaptchaService::class)->verify($payload, VerificationContext::none())->passes())->toBeFalse();
})->with([
    'not base64' => 'this is not base64 %%%',
    'base64 but not json' => base64_encode('plain text'),
    'json but not an object' => base64_encode('[1,2,3]'),
    'object missing fields' => base64_encode('{"algorithm":"SHA-256"}'),
    'unknown algorithm' => base64_encode('{"algorithm":"MD5","challenge":"x","salt":"y","number":1,"signature":"z"}'),
]);

it('serves a fresh challenge over http and never caches it', function (): void {
    $first = $this->getJson('/captcha/challenge');
    $second = $this->getJson('/captcha/challenge');

    $first->assertOk()->assertJsonStructure(['algorithm', 'challenge', 'salt', 'signature', 'maxnumber']);

    // A cached challenge is a challenge handed to two visitors, and the second gets a solution
    // someone else already computed.
    expect($first->json('challenge'))->not->toBe($second->json('challenge'))
        ->and($first->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not mint challenges for a hosted provider', function (): void {
    config()->set('laranail.captcha.provider', 'turnstile');
    app()->forgetInstance(CaptchaService::class);

    // The route exists, but a provider that does not issue challenges has nothing to serve — so
    // an application on Turnstile has no live endpoint here, only a 404.
    $this->getJson('/captcha/challenge')->assertNotFound();
});
