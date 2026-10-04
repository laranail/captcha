<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Captcha\Commands;

use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Captcha\Enums\Provider;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\InteractsWithConsoleWriter;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\InteractsWithConsoleServices;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand as PackageToolsInstallCommand;

/**
 * Publishes what an application needs, and nothing it does not.
 *
 * Worth stating plainly: you do not have to run this. The package ships working defaults — the
 * self-hosted math provider needs no keys, no account and no config file — so `install` exists for
 * applications that want to change something, not as a step between installing and being
 * protected.
 *
 * The base is package-tools' install command, which carries the `::` name support. laranail/console's
 * display API (`$this->services`) and managed run lifecycle come from its two traits rather than its
 * base class, so neither package has to depend on the other. `handle()` is this command's own: the
 * base's generic publish pipeline would print different steps, and the output here is the contract.
 */
final class InstallCommand extends PackageToolsInstallCommand
{
    use InteractsWithConsoleServices;
    use InteractsWithConsoleWriter;

    public const string SIGNATURE = 'laranail::captcha.install {--migrations : Also publish the optional settings-table migration}';

    public const string DESCRIPTION = 'Publish the captcha config file, and optionally the settings migration.';

    public function __construct(Package $package)
    {
        // Listed in `php artisan list`, as it always has been: the base hides install commands by
        // default, so visibility is passed explicitly rather than inherited.
        parent::__construct($package, self::SIGNATURE, hidden: false);

        // The base writes `Install {package}` as the description during construction; restore the
        // one this command has always shown. Both the property and Symfony's copy are set, because
        // the parent constructor has already pushed the property through setDescription().
        $this->description = self::DESCRIPTION;
        $this->setDescription(self::DESCRIPTION);

        // Booted eagerly, as console's own base does, so `$this->services` exists straight after
        // construction rather than only once run() has been entered.
        $this->bootConsoleSupport();
    }

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
