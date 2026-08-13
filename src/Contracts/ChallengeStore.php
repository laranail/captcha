<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

/**
 * Records which self-hosted challenges have been spent.
 *
 * A proof-of-work challenge is only worth anything once. Without this, one solved challenge is a
 * reusable pass: the client keeps posting the same valid payload and every submission verifies,
 * because the maths still checks out. The signature proves the challenge came from us, not that it
 * is being redeemed for the first time.
 *
 * Bounded on purpose. The challenge endpoint is unauthenticated by nature, so an attacker can mint
 * challenges as fast as they can request them; entries expire on their own and the endpoint is
 * rate-limited, so the store cannot be grown without bound.
 */
interface ChallengeStore
{
    /** Returns false if this salt was already redeemed. Must be atomic. */
    public function redeem(string $salt, int $ttlSeconds): bool;
}
