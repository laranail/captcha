<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Contracts;

/**
 * Makes a verified token single-use within this application.
 *
 * The vendors mostly do this themselves — Cloudflare answers `timeout-or-duplicate` on a second
 * siteverify for the same token — but "mostly" is doing real work in that sentence. Two requests
 * carrying the same token can race the vendor's own bookkeeping, a self-hosted ALTCHA payload has
 * no vendor to ask at all, and an adapter whose verification is cached would never reach the
 * vendor twice. Recording redemption locally closes all three.
 */
interface ReplayGuard
{
    /**
     * Claim this token, returning false if it was already claimed.
     *
     * Must be atomic. A read-then-write leaves exactly the race this exists to close: two
     * concurrent submissions of the same token both read "unseen" and both proceed.
     *
     * The token is never stored as given — implementations key on a hash, because a store of live
     * captcha tokens is itself worth stealing.
     */
    public function claim(string $token, int $ttlSeconds): bool;
}
