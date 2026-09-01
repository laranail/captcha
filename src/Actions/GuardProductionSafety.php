<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Actions;

use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Exceptions\UnsafeCaptchaConfiguration;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;

/**
 * Refuse a configuration that would verify everything in production.
 *
 * Two ways in: the null adapter, and the published test keys. Both look completely healthy —
 * valid config, successful verification, no error — while accepting every submission.
 *
 * The interesting part is what counts as production. Asking `app()->environment()` is not enough,
 * because `APP_ENV` is a deployment name and some products treat it as a feature flag: Worksuite
 * ships `codecanyon` on live installations. An environment name that is in neither the allow-list
 * nor the deny-list would sail past a naive check, so the deny-list is consulted first and the
 * caller may pass an explicit environment that overrides the container's — the same shape
 * `licence-override-vendor-presets` arrived at for the same reason.
 */
final readonly class GuardProductionSafety
{
    /**
     * @param  list<string>  $productionEnvironments
     */
    public function __construct(
        private array $productionEnvironments = ['production', 'prod'],
        private bool $allowNullInProduction = false,
    ) {}

    /**
     * @throws UnsafeCaptchaConfiguration
     */
    public function __invoke(Provider $provider, Credentials $credentials, string $environment): void
    {
        if (! $this->isProduction($environment)) {
            return;
        }

        if ($provider === Provider::NullProvider && ! $this->allowNullInProduction) {
            throw UnsafeCaptchaConfiguration::nullProviderInProduction();
        }

        if ($credentials->source === CredentialSource::TestKeys) {
            throw UnsafeCaptchaConfiguration::testKeysInProduction($provider);
        }
    }

    public function isProduction(string $environment): bool
    {
        return in_array(strtolower($environment), $this->productionEnvironments, true);
    }
}
