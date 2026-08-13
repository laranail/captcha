<?php

declare(strict_types=1);

use AltchaOrg\Altcha\V1\Altcha as ReferenceAltcha;
use AltchaOrg\Altcha\V1\ChallengeOptions as ReferenceChallengeOptions;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\ValueObjects\Challenge;

/**
 * Wire compatibility with the reference implementation, proved in both directions.
 *
 * This package implements ALTCHA's proof-of-work directly rather than depending on
 * `altcha-org/altcha` at runtime — the classic scheme is `SHA-256(salt + number)` with an HMAC
 * signature, which is small enough that a dependency buys nothing. What a dependency *would* have
 * bought is the guarantee that our bytes match everyone else's, so that guarantee is bought here
 * instead: the library is a dev dependency, and these tests cross-check against it.
 *
 * Both directions matter and fail differently. A challenge we issue that the reference rejects
 * means real widgets will not solve ours. A challenge the reference issues that we reject means we
 * would break against any server or widget built on the library.
 *
 * Tagged `altcha` so CI's "no optional integrations" job can exclude them and prove the package
 * works with the library absent. Without the tag that job excludes nothing, which is how it sat
 * for a while — passing, and proving nothing.
 */
beforeEach(function (): void {
    config()->set('laranail.captcha.provider', 'altcha');
    config()->set('laranail.captcha.providers.altcha.hmac_key', 'a-shared-server-key');

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
});

/** Do what a browser does: count until the hash matches. */
function solveFor(string $algorithm, string $salt, string $target, int $maxNumber): int
{
    $php = ['SHA-256' => 'sha256', 'SHA-384' => 'sha384', 'SHA-512' => 'sha512'][$algorithm];

    for ($candidate = 0; $candidate <= $maxNumber; $candidate++) {
        if (hash($php, $salt . $candidate) === $target) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unsolvable challenge.');
}

function encodePayload(array $payload): string
{
    return base64_encode((string) json_encode($payload));
}

it('issues a challenge the reference implementation accepts', function (): void {
    $challenge = app(CaptchaService::class)->issueChallenge();

    expect($challenge)->toBeInstanceOf(Challenge::class);

    $payload = [
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'number' => solveFor($challenge->algorithm, $challenge->salt, $challenge->challenge, $challenge->maxNumber),
        'salt' => $challenge->salt,
        'signature' => $challenge->signature,
    ];

    $reference = new ReferenceAltcha('a-shared-server-key');

    // If this fails, real ALTCHA widgets and servers disagree with what we mint.
    expect($reference->verifySolution($payload))->toBeTrue();
})->group('altcha');

it('accepts a challenge the reference implementation issued', function (): void {
    $reference = new ReferenceAltcha('a-shared-server-key');

    // Issued with an expiry, because this adapter refuses a challenge that carries none — see the
    // note on that assertion below. Every challenge we mint has one, so this matches real usage.
    $challenge = $reference->createChallenge(new ReferenceChallengeOptions(
        maxNumber: 5_000,
        expires: new DateTimeImmutable('+5 minutes'),
    ));

    $payload = encodePayload([
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'number' => solveFor($challenge->algorithm, $challenge->salt, $challenge->challenge, 5_000),
        'salt' => $challenge->salt,
        'signature' => $challenge->signature,
    ]);

    // The other direction: we must not reject what the wider ecosystem produces.
    expect(app(CaptchaService::class)->verify($payload)->passes())->toBeTrue();
})->group('altcha');

it('rejects a reference challenge signed with a different key', function (): void {
    $reference = new ReferenceAltcha('a-different-server-key');
    $challenge = $reference->createChallenge(new ReferenceChallengeOptions(maxNumber: 2_000));

    $payload = encodePayload([
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'number' => solveFor($challenge->algorithm, $challenge->salt, $challenge->challenge, 2_000),
        'salt' => $challenge->salt,
        'signature' => $challenge->signature,
    ]);

    // Well-formed, correctly solved, and not ours. Accepting it would mean anyone who can mint a
    // challenge can mint one for us.
    expect(app(CaptchaService::class)->verify($payload)->passes())->toBeFalse();
})->group('altcha');

it('emits the field names the browser widget reads', function (): void {
    $ours = array_keys(app(CaptchaService::class)->issueChallenge()->toArray());

    // Asserted against the documented wire contract, not against the reference object's PHP
    // property names. The library exposes `maxNumber` as a property and ships no JsonSerializable,
    // so `json_encode()` on it produces camelCase — which is the library's naming, not ALTCHA's.
    // The widget reads `maxnumber`. Comparing against the wrong authority here would have had us
    // "fix" a correct implementation into a broken one.
    expect($ours)->toEqualCanonicalizing(['algorithm', 'challenge', 'salt', 'signature', 'maxnumber']);
})->group('altcha');

it('refuses a challenge that carries no expiry', function (): void {
    $reference = new ReferenceAltcha('a-shared-server-key');
    $challenge = $reference->createChallenge(new ReferenceChallengeOptions(maxNumber: 1_000));

    $payload = encodePayload([
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'number' => solveFor($challenge->algorithm, $challenge->salt, $challenge->challenge, 1_000),
        'salt' => $challenge->salt,
        'signature' => $challenge->signature,
    ]);

    // A deliberate divergence, documented rather than silently inherited: the reference treats
    // expiry as optional, and a challenge that never expires is a token with no shelf life. This
    // adapter both issues and verifies its own challenges, so requiring one costs nothing and
    // closes the window.
    expect(app(CaptchaService::class)->verify($payload)->passes())->toBeFalse();
})->group('altcha');
