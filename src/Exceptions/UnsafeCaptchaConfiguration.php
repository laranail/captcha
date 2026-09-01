<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Exceptions;

use RuntimeException;
use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * The configured setup would accept every submission in production.
 *
 * Raised rather than quietly degraded because both cases it covers are invisible from the
 * outside: the application boots, the config validates, verification returns success, and nothing
 * is logged. The only symptom is that the captcha stopped working, which nobody notices until the
 * spam arrives.
 *
 * Never allowed to reach the visitor. The service catches it, records a failed verification and
 * logs at error level, so a production misconfiguration blocks submissions instead of returning a
 * 500 — and the message, which names the fix, stays in the log rather than on the page.
 */
final class UnsafeCaptchaConfiguration extends RuntimeException
{
    public static function testKeysInProduction(Provider $provider): self
    {
        return new self(sprintf(
            'The [%s] provider is resolving the published test keys in production, which verify '
            .'every token. Set real credentials for this environment, or set '
            .'laranail.captcha.credentials.test_keys.enabled to false to fail loudly instead.',
            $provider->value,
        ));
    }

    public static function nullProviderInProduction(): self
    {
        return new self(
            'The [null] captcha provider is active in production and accepts every submission. '
            .'Choose a real provider, or set laranail.captcha.providers.null.allow_in_production '
            .'to true if this environment is deliberately unprotected.',
        );
    }
}
