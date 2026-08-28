<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Commands;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Captcha\Support\CaptchaConfig;
use Simtabi\Laranail\Captcha\Enums\CredentialSource;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Captcha\Actions\GuardProductionSafety;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Answers the question you actually have during an incident.
 *
 * Not "is a key set" — the config file always looks fine — but "which of the three sources is
 * serving this key right now, and is anything about that arrangement going to quietly accept every
 * submission". Those are the failures that leave an application looking completely healthy.
 */
final class DoctorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::captcha.doctor';

    /** @var list<string> */
    protected array $commandAliases = ['captcha:doctor'];

    protected $description = 'Report the active captcha provider, where its credentials come from, and anything unsafe.';

    public function handle(
        CaptchaService $captcha,
        ResolveCredentials $resolveCredentials,
        GuardProductionSafety $guard,
        CaptchaConfig $config,
    ): int {
        $provider = $captcha->provider();
        $environment = $resolveCredentials->environment();
        $credentials = $resolveCredentials($provider);

        $this->services->display()->header('laranail/captcha');

        $this->services->display()->keyValue([
            'Provider'          => $provider->label() . ' (' . $provider->value . ')',
            'Environment'       => $environment,
            'Configured'        => $captcha->isConfigured() ? 'yes' : 'no',
            'Credential source' => $credentials->source->label(),
            'Site key'          => $this->redact($credentials->siteKey),
            'Secret'            => $credentials->secret === '' ? '(none)' : '(set, redacted)',
        ]);

        $problems = $this->problems($provider, $credentials->source, $environment, $guard, $config, $captcha);

        foreach ($problems as $problem) {
            $this->services->display()->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->services->display()->success('No problems found.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function problems(
        Provider $provider,
        CredentialSource $source,
        string $environment,
        GuardProductionSafety $guard,
        CaptchaConfig $config,
        CaptchaService $captcha,
    ): array {
        $problems = [];

        if (! $captcha->isConfigured()) {
            $problems[] = "The [{$provider->value}] provider has no usable credentials in [{$environment}].";
        }

        // The headline check, and the one nobody thinks to make: an application running on keys
        // that verify every token looks perfectly configured from every other angle.
        if ($guard->isProduction($environment) && ! $source->isProductionSafe()) {
            $problems[] = "Production is resolving credentials from [{$source->value}], which accepts every submission.";
        }

        // Enforcement that compares nothing is a setting claiming a guarantee it does not provide,
        // so this fails rather than warns: a token minted on someone else's copy of your form
        // verifies here, and the config file says otherwise.
        //
        // Self-hosted providers are exempt because there is nothing to compare. A math or ALTCHA
        // challenge is solved against this application; no vendor returns a hostname for it. Firing
        // here made `doctor` exit non-zero on a default install — the zero-config path the README
        // promises — and advised setting a key that would not have changed the outcome.
        if (! $provider->isSelfHosted()
            && $config->bool('verification.enforce_hostname', true)
            && $config->strings('verification.allowed_hostnames') === []) {
            $problems[] = 'Hostname enforcement is on but no hostnames are listed, so nothing is compared. '
                . 'Set laranail.captcha.verification.allowed_hostnames.';
        }

        if ($provider->isScoreBased() && $config->float('verification.score.allow', 0.5) <= 0.0) {
            $problems[] = 'A score threshold of zero accepts every score, which disables the only check '
                . 'a score-based provider offers.';
        }

        return $problems;
    }

    private function redact(string $value): string
    {
        if ($value === '') {
            return '(none)';
        }

        // The site key is public by design, but printing it in full invites pasting terminal
        // output into an issue, and the same output carries the environment and source.
        return mb_substr($value, 0, 6) . str_repeat('*', max(0, mb_strlen($value) - 6));
    }
}
