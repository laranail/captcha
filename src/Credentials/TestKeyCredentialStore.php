<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Credentials;

use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\ValueObjects\Credentials;

/**
 * The providers' own published always-pass keys, so a fresh checkout works with no setup.
 *
 * These are documented by each vendor for exactly this purpose and are public knowledge; they are
 * not secrets and there is nothing to leak. What they are is completely worthless as protection —
 * every one of them verifies any token — so the danger is not the keys themselves but an
 * application reaching production still using them and looking configured while accepting
 * everything.
 *
 * Two things guard that. This store is only consulted in environments the operator listed, and
 * {@see GuardProductionSafety} refuses to verify at all when the
 * resolved source is this one and the environment is production. The second check is the one that
 * matters, because the first trusts `APP_ENV` — and `APP_ENV` lies more often than you would
 * expect.
 */
final readonly class TestKeyCredentialStore implements CredentialStore
{
    /**
     * Sourced from each vendor's own testing documentation.
     *
     * Turnstile's `1x…` pair always passes; the `2x…` and `3x…` variants that always fail or
     * report an already-spent token are documented in `docs/testing.md` for tests that need a
     * failure rather than wired in here.
     *
     * @var array<string, array{site_key: string, secret: string}>
     */
    private const array KEYS = [
        'turnstile' => [
            'site_key' => '1x00000000000000000000AA',
            'secret' => '1x0000000000000000000000000000000AA',
        ],
        'hcaptcha' => [
            'site_key' => '10000000-ffff-ffff-ffff-000000000001',
            'secret' => '0x0000000000000000000000000000000000000000',
        ],
        'recaptcha-v2' => [
            'site_key' => '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI',
            'secret' => '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
        ],
        'recaptcha-v2-invisible' => [
            'site_key' => '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI',
            'secret' => '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
        ],
    ];

    /**
     * @param list<string> $allowedEnvironments
     */
    public function __construct(
        private bool $enabled = true,
        private array $allowedEnvironments = ['local', 'testing'],
    ) {}

    public function get(Provider $provider, string $environment): ?Credentials
    {
        if (! $this->enabled || ! in_array($environment, $this->allowedEnvironments, true)) {
            return null;
        }

        $keys = self::KEYS[$provider->value] ?? null;

        // Not every provider publishes test keys. Friendly Captcha, Arkose and Enterprise all
        // require a real account, and ALTCHA has no vendor to publish any — those fall through
        // and report themselves unconfigured, which is the honest answer.
        if ($keys === null) {
            return null;
        }

        return new Credentials(
            siteKey: $keys['site_key'],
            secret: $keys['secret'],
            source: CredentialSource::TestKeys,
        );
    }
}
