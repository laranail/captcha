<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Commands;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Captcha\Actions\ResolveCredentials;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Where every provider's credentials are coming from, for one environment.
 *
 * The provenance is the point. "Is the key set" is answerable from the config file; "is this key
 * arriving from the database, the config, or the published test keys" is not, and it is the
 * question behind every "why is the captcha passing everything" report.
 *
 * Secrets are never printed. Site keys are truncated, because terminal output ends up pasted into
 * issues and this output also names the environment.
 */
final class KeysCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::captcha.keys';

    /** @var list<string> */
    protected array $commandAliases = ['captcha:keys'];

    protected $description = 'Show which source each provider’s credentials resolve from, redacted.';

    public function handle(ResolveCredentials $resolveCredentials): int
    {
        $rows = [];

        foreach (Provider::cases() as $provider) {
            $credentials = $resolveCredentials($provider);

            // The self-hosted and null providers have no vendor credentials at all, so an empty
            // row for them would read as a misconfiguration rather than as "not applicable".
            if ($provider->isSelfHosted() || $provider === Provider::NullProvider) {
                $rows[] = [$provider->value, 'n/a', 'no credentials needed', ''];

                continue;
            }

            $rows[] = [
                $provider->value,
                $credentials->source->value,
                $credentials->isComplete() ? 'complete' : ($credentials->siteKey === '' && $credentials->secret === ''
                    ? 'not set'
                    : 'incomplete'),
                $this->redact($credentials->siteKey),
            ];
        }

        $this->services->display()->header('Credentials · ' . $resolveCredentials->environment());
        $this->services->display()->displayTable(['Provider', 'Source', 'State', 'Site key'], $rows);

        return self::SUCCESS;
    }

    private function redact(string $value): string
    {
        return $value === ''
            ? '—'
            : mb_substr($value, 0, 6) . str_repeat('*', max(0, mb_strlen($value) - 6));
    }
}
