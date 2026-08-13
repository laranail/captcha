<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Altcha;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengeStore;
use Simtabi\Laranail\Captcha\Contracts\IssuesChallenges;
use Simtabi\Laranail\Captcha\Enums\ErrorCode;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Challenge;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationContext;
use Simtabi\Laranail\Captcha\ValueObjects\VerificationResult;
use Simtabi\Laranail\Captcha\ValueObjects\Widget;
use Throwable;

/**
 * ALTCHA — self-hosted proof-of-work. No vendor, no round trip, no cookies.
 *
 * The scheme is small enough to implement directly rather than take a runtime dependency for: the
 * server picks a secret number, publishes `hash(salt + number)` with an HMAC over it, and the
 * browser finds the number by counting. `altcha-org/altcha` is kept as a dev dependency and the
 * test suite cross-checks this implementation against the reference, which is a stronger guarantee
 * than depending on it would be — it proves wire compatibility rather than assuming it.
 *
 * Three things carry the security, and all three are easy to get subtly wrong:
 *
 * 1. **The signature.** Without it a client invents its own trivial challenge and solves that. It
 *    is compared with `hash_equals`, never `===`.
 * 2. **Expiry**, carried in the salt so it is covered by the signature. A challenge with an
 *    unsigned expiry is a challenge with no expiry.
 * 3. **Single use.** The maths still checks out on the tenth replay of a solved payload, so
 *    redemption is recorded and a repeat is rejected. This is the one the vendor providers get for
 *    free and a self-hosted scheme does not.
 */
final readonly class AltchaAdapter implements CaptchaAdapter, IssuesChallenges
{
    /** Anything outside this set is refused rather than passed to `hash()`. */
    private const array ALGORITHMS = [
        'SHA-256' => 'sha256',
        'SHA-384' => 'sha384',
        'SHA-512' => 'sha512',
    ];

    public function __construct(
        #[SensitiveParameter]
        private string $hmacKey,
        private ChallengeStore $challenges,
        private ClockInterface $clock,
        private int $maxNumber = 100_000,
        private int $expiresAfterSeconds = 300,
        private string $algorithm = 'SHA-256',
        private string $challengeUrl = '/captcha/challenge',
    ) {}

    public function issue(): Challenge
    {
        $expiresAt = $this->clock->now()->modify("+{$this->expiresAfterSeconds} seconds");

        // The expiry rides inside the salt so the HMAC covers it. Sent as a separate field it
        // would be client-editable, and a challenge whose expiry can be rewritten never expires.
        $salt = bin2hex(random_bytes(12)) . '?expires=' . $expiresAt->getTimestamp();

        $number = random_int(0, $this->maxNumber);
        $challenge = $this->hash($salt . $number);

        return new Challenge(
            algorithm: $this->algorithm,
            challenge: $challenge,
            salt: $salt,
            signature: $this->sign($challenge),
            maxNumber: $this->maxNumber,
            expiresAt: $expiresAt,
        );
    }

    public function verify(string $token, VerificationContext $context): VerificationResult
    {
        if (! $this->isConfigured()) {
            return VerificationResult::failed(ErrorCode::NotConfigured);
        }

        $payload = $this->decode($token);

        if ($payload === null) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        ['algorithm' => $algorithm, 'challenge' => $challenge, 'salt' => $salt,
            'number' => $number, 'signature' => $signature] = $payload;

        // Taken from the payload rather than assumed, so a client on an older widget still
        // verifies — but constrained to the known set first, because this string reaches `hash()`.
        if (! array_key_exists($algorithm, self::ALGORITHMS)) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        if ($this->hasExpired($salt)) {
            return VerificationResult::failed(ErrorCode::Stale);
        }

        $expectedChallenge = $this->hash($salt . $number, $algorithm);

        if (! hash_equals($expectedChallenge, $challenge)
            || ! hash_equals($this->sign($expectedChallenge, $algorithm), $signature)) {
            return VerificationResult::failed(ErrorCode::InvalidResponse);
        }

        // Last, and only once everything else has passed: a rejected payload must not consume the
        // salt, or one malformed submission would lock the visitor out of retrying.
        if (! $this->challenges->redeem($salt, $this->expiresAfterSeconds * 2)) {
            return VerificationResult::failed(ErrorCode::Replayed);
        }

        return VerificationResult::passed(challengeAt: $this->clock->now());
    }

    public function widget(string $instanceId): Widget
    {
        return new Widget(
            provider: Provider::Altcha,
            instanceId: $instanceId,
            containerClass: 'altcha-widget',
            attributes: ['challengeurl' => $this->challengeUrl],
        );
    }

    public function isConfigured(): bool
    {
        return $this->hmacKey !== '';
    }

    public function provider(): Provider
    {
        return Provider::Altcha;
    }

    /**
     * @return array{algorithm: string, challenge: string, salt: string, number: int, signature: string}|null
     */
    private function decode(string $token): ?array
    {
        $json = base64_decode($token, true);

        if ($json === false) {
            return null;
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        foreach (['algorithm', 'challenge', 'salt', 'signature'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || $payload[$key] === '') {
                return null;
            }
        }

        if (! isset($payload['number']) || ! is_int($payload['number'])) {
            return null;
        }

        return [
            'algorithm' => $payload['algorithm'],
            'challenge' => $payload['challenge'],
            'salt' => $payload['salt'],
            'number' => $payload['number'],
            'signature' => $payload['signature'],
        ];
    }

    private function hasExpired(string $salt): bool
    {
        $query = parse_url($salt, PHP_URL_QUERY);

        // No expiry in the salt means the challenge did not come from this implementation. Treat
        // it as stale rather than as valid-forever.
        if (! is_string($query)) {
            return true;
        }

        parse_str($query, $params);

        $expires = $params['expires'] ?? null;

        if (! is_string($expires) || ! ctype_digit($expires)) {
            return true;
        }

        return (new DateTimeImmutable('@' . $expires)) < $this->clock->now();
    }

    private function hash(string $value, ?string $algorithm = null): string
    {
        return hash(self::ALGORITHMS[$algorithm ?? $this->algorithm], $value);
    }

    private function sign(string $challenge, ?string $algorithm = null): string
    {
        return hash_hmac(self::ALGORITHMS[$algorithm ?? $this->algorithm], $challenge, $this->hmacKey);
    }
}
