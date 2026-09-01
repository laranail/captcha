<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\ValueObjects;

use SensitiveParameter;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;

/**
 * One provider's keys for one environment, plus where they were found.
 *
 * The source is not bookkeeping. It is what lets the production guard refuse a request that would
 * be verified with published test keys, and what lets `laranail::captcha.keys` answer "why is this
 * passing everything" without anyone having to read three config files.
 */
final readonly class Credentials
{
    /**
     * @param  array<string, string>  $extra  provider-specific values: an Enterprise project id, an
     *                                        Arkose client subdomain, a Friendly Captcha region
     */
    public function __construct(
        public string $siteKey,
        #[SensitiveParameter]
        public string $secret,
        public CredentialSource $source,
        public array $extra = [],
    ) {}

    /**
     * Redact the secret whenever this object is dumped.
     *
     * `dd($credentials)`, a Whoops or Ignition frame, a queue payload written to `failed_jobs`,
     * and `var_dump` in a test all reach for this. The secret is the one value that must not end
     * up in any of them — and the default behaviour is to print it in full.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'siteKey' => $this->siteKey,
            'secret' => $this->secret === '' ? '' : '[redacted]',
            'source' => $this->source->value,
            'extra' => array_map(
                static fn (string $value): string => $value === '' ? '' : '[redacted]',
                $this->extra,
            ),
        ];
    }

    public static function missing(): self
    {
        return new self('', '', CredentialSource::None);
    }

    /** Whether both halves are present. A half-configured provider is not usable. */
    public function isComplete(): bool
    {
        return $this->siteKey !== '' && $this->secret !== '';
    }

    public function extra(string $key, ?string $default = null): ?string
    {
        $value = $this->extra[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }
}
