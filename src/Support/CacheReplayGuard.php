<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Support;

use Illuminate\Contracts\Cache\Repository;
use Simtabi\Laranail\Captcha\Contracts\ChallengeStore;
use Simtabi\Laranail\Captcha\Contracts\ReplayGuard;
use Throwable;

/**
 * Single-use enforcement backed by the cache's atomic add.
 *
 * `add()` rather than `has()` then `put()`: the latter is a read-then-write, and two concurrent
 * submissions of the same token both read "unseen" and both proceed — which is precisely the race
 * being closed.
 *
 * Tokens are hashed before they are used as keys. A cache full of live captcha tokens is worth
 * stealing on its own, and cache keys leak into slow-query logs, debug toolbars and Redis
 * `MONITOR` output far more readily than values do.
 */
final readonly class CacheReplayGuard implements ChallengeStore, ReplayGuard
{
    public function __construct(
        private Repository $cache,
        private string $prefix = 'laranail:captcha:seen:',
        private bool $failOpen = false,
    ) {}

    public function claim(string $token, int $ttlSeconds): bool
    {
        return $this->add($this->prefix . hash('xxh128', $token), $ttlSeconds);
    }

    public function redeem(string $salt, int $ttlSeconds): bool
    {
        return $this->add($this->prefix . 'altcha:' . hash('xxh128', $salt), $ttlSeconds);
    }

    private function add(string $key, int $ttlSeconds): bool
    {
        try {
            return $this->cache->add($key, true, $ttlSeconds);
        } catch (Throwable) {
            // The cache being down is an infrastructure failure, not a replay. Which way to
            // resolve it is a genuine trade-off, so it is configuration rather than a guess:
            // failing closed rejects real visitors for the duration of a Redis outage, and
            // failing open re-opens the replay window. The default is closed, because the
            // window is the thing this class exists to shut.
            return $this->failOpen;
        }
    }
}
