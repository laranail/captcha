<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

use DateTimeImmutable;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;

/**
 * A self-hosted proof-of-work challenge, as handed to the browser.
 *
 * The signature is an HMAC of the challenge hash under a server-held key, and it is the whole
 * security of the scheme: without it a client could invent its own easy challenge and solve that
 * instead. It is verified with `hash_equals` on the way back — a `===` comparison on an HMAC is
 * timing-attackable, and it is the single most-copied mistake in proof-of-work implementations.
 */
final readonly class Challenge implements ChallengePayload
{
    public function __construct(
        public string $algorithm,
        public string $challenge,
        public string $salt,
        public string $signature,
        public int $maxNumber,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * The JSON body the widget expects from the challenge endpoint.
     *
     * Field names are the ALTCHA wire format, not ours, so they stay snake-free and exactly as
     * specified — a renamed key here silently breaks the browser widget, which fails by never
     * producing a token rather than by erroring.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'challenge' => $this->challenge,
            'salt'      => $this->salt,
            'signature' => $this->signature,
            'maxnumber' => $this->maxNumber,
        ];
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }
}
