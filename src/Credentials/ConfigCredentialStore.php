<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Credentials;

use Illuminate\Support\Str;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;

/**
 * Credentials from the environment-keyed config blocks.
 *
 * `laranail.captcha.environments.{env}.{provider}` layered over
 * `laranail.captcha.environments.default.{provider}`, so an application that only ever has one key
 * pair writes it once under `default` and never thinks about environments again, while one that
 * holds staging and production keys side by side can.
 *
 * Environment names are matched exactly first and then as wildcard patterns, because deployment
 * names are not a closed set — `production`, `prod`, `production-eu`, `review-app-1234`. A
 * `production*` pattern covers a family without enumerating it.
 */
final readonly class ConfigCredentialStore implements CredentialStore
{
    public function __construct(private Repository $config) {}

    public function get(Provider $provider, string $environment): ?Credentials
    {
        /** @var array<string, mixed> $environments */
        $environments = (array) $this->config->get('laranail.captcha.environments', []);

        /** @var array<string, mixed> $block */
        $block = array_replace_recursive(
            $this->providerBlock($environments, 'default', $provider),
            $this->providerBlock($environments, $environment, $provider),
        );

        $siteKey = $this->stringValue($block, 'site_key');
        $secret = $this->stringValue($block, 'secret');

        // Nothing to say, rather than blank credentials: the chain has to be able to move on to
        // the next store, and empty strings would look like an answer.
        if ($siteKey === '' && $secret === '') {
            return null;
        }

        unset($block['site_key'], $block['secret']);

        return new Credentials(
            siteKey: $siteKey,
            secret: $secret,
            source: CredentialSource::Config,
            extra: $this->stringMap($block),
        );
    }

    /**
     * @param array<string, mixed> $environments
     *
     * @return array<string, mixed>
     */
    private function providerBlock(array $environments, string $environment, Provider $provider): array
    {
        $block = $environments[$environment] ?? null;

        if (! is_array($block)) {
            foreach ($environments as $pattern => $candidate) {
                if (is_string($pattern) && is_array($candidate) && Str::is($pattern, $environment)) {
                    $block = $candidate;
                    break;
                }
            }
        }

        if (! is_array($block)) {
            return [];
        }

        $providerBlock = $block[$provider->value] ?? [];

        if (! is_array($providerBlock)) {
            return [];
        }

        /** @var array<string, mixed> $keyed */
        $keyed = array_filter($providerBlock, is_string(...), ARRAY_FILTER_USE_KEY);

        return $keyed;
    }

    /**
     * Provider-specific extras, keyed and valued as strings.
     *
     * Non-scalars are dropped rather than stringified: a nested array in a credentials block is a
     * configuration mistake, and `Array` is a worse value to carry forward than nothing.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, string>
     */
    private function stringMap(array $block): array
    {
        $extra = [];

        foreach ($block as $key => $value) {
            if (is_scalar($value)) {
                $extra[$key] = (string) $value;
            }
        }

        return $extra;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stringValue(array $block, string $key): string
    {
        $value = $block[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
