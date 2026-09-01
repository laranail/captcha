<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Adapters\Math;

use DateTimeImmutable;
use Simtabi\Laranail\Captcha\Contracts\ChallengePayload;

/**
 * One arithmetic question, as the browser sees it.
 *
 * Note what is *not* here: the answer. It never leaves the server, in any form — not as a hidden
 * field, not as a hash the client could grind against offline. A math captcha whose answer is
 * derivable from what it sends is a spam-bot speed bump, and most of them are exactly that.
 */
final readonly class MathProblem implements ChallengePayload
{
    public function __construct(
        public string $id,
        public string $question,
        public string $signature,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'signature' => $this->signature,
            'expires_at' => $this->expiresAt->getTimestamp(),
        ];
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }
}
