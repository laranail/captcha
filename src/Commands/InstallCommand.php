<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Commands;

use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Publishes what an application needs, and nothing it does not.
 *
 * Worth stating plainly: you do not have to run this. The package ships working defaults — the
 * self-hosted math provider needs no keys, no account and no config file — so `install` exists for
 * applications that want to change something, not as a step between installing and being
 * protected.
 */
final class InstallCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::captcha.install';

    /** @var list<string> */
    protected array $commandAliases = ['captcha:install'];

    protected $signature = 'laranail::captcha.install {--migrations : Also publish the optional settings-table migration}';

    protected $description = 'Publish the captcha config file, and optionally the settings migration.';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'laranail::captcha-config']);
        $this->services->display()->success('Published config/laranail/captcha.php');

        if ($this->option('migrations')) {
            // Optional because most applications already have somewhere to keep settings, and the
            // package binds to whatever model they point it at. A second settings table is how a
            // package ends up ignored.
            $this->callSilently('vendor:publish', ['--tag' => 'laranail::captcha-migrations']);
            $this->services->display()->success('Published the captcha_settings migration.');
        }

        $this->services->display()->info(sprintf(
            'Active provider: %s. Drop <x-captcha /> in a form and add \'captcha\' => \'captcha\' to its rules.',
            $this->activeProvider(),
        ));

        return self::SUCCESS;
    }

    private function activeProvider(): string
    {
        $configured = config('laranail.captcha.provider');

        $provider = is_string($configured) ? Provider::tryFrom($configured) : null;

        return $provider instanceof Provider ? $provider->value : 'unknown';
    }
}
