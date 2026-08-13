<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

/**
 * An optional capability: this adapter mints its own challenges rather than a vendor's.
 *
 * Separate from {@see CaptchaAdapter} rather than folded into it, because only the self-hosted
 * providers can implement it and a port that most implementors have to stub is a worse port. The
 * service checks `instanceof`, and the challenge endpoint answers 404 when the active adapter does
 * not implement this — so an application on a hosted provider has no live public route here.
 */
interface IssuesChallenges
{
    /**
     * Mint a fresh, single-use challenge.
     *
     * Every call must produce a new one. Reusing a challenge across visitors defeats the
     * proof-of-work: the second visitor gets a solution someone else already computed.
     */
    public function issue(): ChallengePayload;
}
