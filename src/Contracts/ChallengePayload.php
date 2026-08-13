<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

use DateTimeImmutable;

/**
 * What a self-hosted provider hands the browser.
 *
 * The two self-hosted providers send very different things — ALTCHA sends a hash to grind against,
 * the math provider sends a question to read — so this is the small amount they have in common:
 * a JSON body for the challenge endpoint, and an expiry.
 */
interface ChallengePayload
{
    /**
     * The response body for the challenge endpoint.
     *
     * Must never contain the answer, or anything from which the answer can be derived. That is the
     * entire discipline of a self-hosted challenge: the browser gets a question and a signature,
     * and the server keeps the only copy of what a correct response looks like.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public function hasExpired(DateTimeImmutable $now): bool;
}
