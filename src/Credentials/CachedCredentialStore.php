<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Credentials;

use Illuminate\Contracts\Cache\Repository;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Throwable;

/**
 * Caches a store's answers, and is off by default on purpose.
 *
 * Enabling it writes a decrypted provider secret into whatever backs the cache. That is usually a
 * shared Redis with weaker access control than the database the secret was encrypted in, one that
 * several applications share, and one whose contents show up in `MONITOR` output — so switching
 * this on moves the secret somewhere less protected than where it started. A settings lookup is a
 * single indexed query; most applications should leave this alone and pay it.
 *
 * When it is on, a cache outage falls through to the underlying store rather than failing. The
 * cache is an optimisation, and an optimisation that can take down logins is not one.
 */
final readonly class CachedCredentialStore implements CredentialStore
{
    public function __construct(
        private CredentialStore $inner,
        private Repository $cache,
        private int $ttlSeconds = 300,
        private string $prefix = 'laranail:captcha:credentials:',
    ) {}

    public function get(Provider $provider, string $environment): ?Credentials
    {
        $key = $this->prefix.$provider->value.':'.$environment;

        try {
            /** @var array<string, mixed>|null $cached */
            $cached = $this->cache->get($key);

            if (is_array($cached)) {
                return $this->hydrate($cached);
            }
        } catch (Throwable) {
            // Cache unavailable. Fall through and resolve uncached, exactly as
            // laravel-toggle's ToggleManager does — the alternative is that a Redis outage
            // becomes a captcha outage becomes a login outage.
            return $this->inner->get($provider, $environment);
        }

        $credentials = $this->inner->get($provider, $environment);

        if ($credentials instanceof Credentials) {
            try {
                $this->cache->put($key, $this->dehydrate($credentials), $this->ttlSeconds);
            } catch (Throwable) {
                // Writing through is best-effort for the same reason reading is.
            }
        }

        return $credentials;
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrate(Credentials $credentials): array
    {
        return [
            'site_key' => $credentials->siteKey,
            'secret' => $credentials->secret,
            'source' => $credentials->source->value,
            'extra' => $credentials->extra,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extra(mixed $extra): array
    {
        if (! is_array($extra)) {
            return [];
        }

        $values = [];

        foreach ($extra as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function hydrate(array $cached): Credentials
    {
        return new Credentials(
            siteKey: is_string($cached['site_key'] ?? null) ? $cached['site_key'] : '',
            secret: is_string($cached['secret'] ?? null) ? $cached['secret'] : '',
            source: CredentialSource::tryFrom(
                is_string($cached['source'] ?? null) ? $cached['source'] : '',
            ) ?? CredentialSource::None,
            extra: $this->extra($cached['extra'] ?? null),
        );
    }
}
