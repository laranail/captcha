<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Actions;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;

/**
 * Resolve one provider's keys for the environment this application is running as.
 *
 * Thin on purpose: the chain decides precedence, the stores decide how to look things up, and the
 * environment name is resolved once at registration rather than per call. What is left here is the
 * single seam every adapter goes through — which is what made the whole credential layer possible
 * to swap, and what the old package lacked when it read `config('captcha.sitekey')` inline in two
 * view components.
 */
final readonly class ResolveCredentials
{
    public function __construct(
        private CredentialStore $store,
        private string $environment,
    ) {}

    public function __invoke(Provider $provider): Credentials
    {
        return $this->store->get($provider, $this->environment) ?? Credentials::missing();
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
