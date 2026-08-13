<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Psr\Clock\ClockInterface;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Adapters\Altcha\AltchaAdapter;
use Simtabi\Laranail\Captcha\Adapters\Math\MathCaptchaAdapter;
use Simtabi\Laranail\Captcha\Adapters\Math\ProblemGenerator;
use Simtabi\Laranail\Captcha\Adapters\NullProvider\NullAdapter;
use Simtabi\Laranail\Captcha\Contracts\CaptchaAdapter;
use Simtabi\Laranail\Captcha\Contracts\ChallengeStore;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\CaptchaConfig;
use Simtabi\Laranail\Captcha\Support\CaptchaHttp;

/**
 * Builds the one adapter this application is configured to use.
 *
 * Not an `Illuminate\Support\Manager`. A Manager resolves by interpolating the driver name into a
 * method call, and `RoleManager` in this org goes further and accepts a class name as a driver —
 * fine for a value the application author writes, wrong for one that arrives from a config file an
 * operator edits and, in a multi-tenant install, from a database row. Here the {@see Provider} enum
 * is the allow-list: a name that is not a case never resolves to anything.
 *
 * Custom adapters go through {@see self::extend()}, which takes a closure rather than a class
 * name, so registering one is a deliberate act in application code.
 */
final class AdapterFactory
{
    /** @var array<string, Closure(): CaptchaAdapter> */
    private array $custom = [];

    public function __construct(
        private readonly ResolveCredentials $resolveCredentials,
        private readonly CaptchaHttp $http,
        private readonly Repository $config,
        private readonly CaptchaConfig $settings,
        private readonly ChallengeStore $challenges,
        private readonly ClockInterface $clock,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @param Closure(): CaptchaAdapter $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    public function make(Provider $provider): CaptchaAdapter
    {
        if (isset($this->custom[$provider->value])) {
            return ($this->custom[$provider->value])();
        }

        $credentials = ($this->resolveCredentials)($provider);
        $options = $this->optionsFor($provider);

        return match ($provider) {
            // Two adapters need something other than credentials and an HTTP client. Special-cased
            // here rather than forced into a uniform constructor, because a constructor shaped to
            // fit every adapter would take arguments most of them ignore.
            Provider::NullProvider => new NullAdapter(
                verifies: (bool) ($options['verifies'] ?? true),
            ),
            Provider::Altcha => new AltchaAdapter(
                hmacKey: $this->signingKey($options),
                challenges: $this->challenges,
                clock: $this->clock,
                maxNumber: $this->intOption($options, 'max_number', 100_000),
                expiresAfterSeconds: $this->intOption($options, 'expires_after', 300),
                challengeUrl: $this->challengeRoute(),
            ),
            Provider::Math => new MathCaptchaAdapter(
                hmacKey: $this->signingKey($options),
                cache: $this->cache->store($this->settings->stringOrNull('challenge.store')),
                clock: $this->clock,
                problems: new ProblemGenerator($this->intOption($options, 'difficulty', 2)),
                expiresAfterSeconds: $this->intOption($options, 'expires_after', 300),
                challengeUrl: $this->challengeRoute(),
            ),
            default => new ($provider->adapter())($credentials, $this->http, $options),
        };
    }

    /**
     * The key the self-hosted providers sign their challenges with.
     *
     * Falls back to a value derived from `APP_KEY` rather than `APP_KEY` itself, so a stolen
     * challenge signature reveals nothing about the application key and rotating one does not
     * silently change the meaning of the other. Deriving also means the self-hosted providers work
     * on a fresh install with nothing configured, which is the point of offering them.
     *
     * @param array<string, mixed> $options
     */
    private function signingKey(array $options): string
    {
        $configured = $options['hmac_key'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $appKey = $this->config->get('app.key');

        return is_string($appKey) && $appKey !== ''
            ? hash_hmac('sha256', 'laranail/captcha:challenge-signing', $appKey)
            : '';
    }

    private function challengeRoute(): string
    {
        return $this->settings->stringOrNull('challenge.route') ?? '/captcha/challenge';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;

        // A mistyped value falls back to the documented default rather than becoming a plausible
        // wrong one: `(int) 'five'` is zero, and a zero expiry is a very different setting from a
        // missing one.
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(Provider $provider): array
    {
        $widget = $this->settings->map('widget');
        $options = $this->settings->map('providers.' . $provider->optionsKey());

        // Widget defaults first, so theme, size and language are set once for whichever provider
        // is active and a provider block only has to name what it does differently.
        return [...$widget, ...$options];
    }
}
