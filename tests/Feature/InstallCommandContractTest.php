<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Simtabi\Laranail\Captcha\Commands\InstallCommand;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\InteractsWithConsoleWriter;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\InteractsWithConsoleServices;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand as PackageToolsInstallCommand;

/**
 * The install command's observable surface, pinned.
 *
 * Its base class moved from laranail/console's `Command` to laranail/package-tools'
 * `InstallCommand`, with console's display API kept by `use`-ing its two traits. A base swap is
 * where a name, an option, the listing visibility or a line of output changes without anyone
 * deciding it should, so every one of those is asserted here against what the command did before.
 */
function captchaInstallCommand(): Command
{
    $command = Artisan::all()['laranail::captcha.install'] ?? null;

    expect($command)->toBeInstanceOf(InstallCommand::class);

    /** @var Command $command */
    return $command;
}

it('keeps its name, aliases, description and listing visibility', function (): void {
    $command = captchaInstallCommand();

    expect($command->getName())->toBe('laranail::captcha.install')
        ->and($command->getAliases())->toBe([])
        ->and($command->getDescription())->toBe('Publish the captcha config file, and optionally the settings migration.')
        ->and($command->isHidden())->toBeFalse();
});

it('keeps exactly one option of its own, and no arguments', function (): void {
    $definition = captchaInstallCommand()->getNativeDefinition();

    expect(array_keys($definition->getOptions()))->toBe(['migrations'])
        ->and($definition->getArguments())->toBe([]);

    $option = $definition->getOption('migrations');

    expect($option->acceptValue())->toBeFalse()
        ->and($option->getDescription())->toBe('Also publish the optional settings-table migration');
});

it('publishes the config and names the active provider', function (): void {
    $this->artisan('laranail::captcha.install')
        ->expectsOutputToContain('Published config/laranail/captcha.php')
        ->expectsOutputToContain('Active provider: math.')
        ->doesntExpectOutputToContain('captcha_settings migration')
        ->assertExitCode(0);
});

it('publishes the settings migration only when asked', function (): void {
    $this->artisan('laranail::captcha.install', ['--migrations' => true])
        ->expectsOutputToContain('Published config/laranail/captcha.php')
        ->expectsOutputToContain('Published the captcha_settings migration.')
        ->assertExitCode(0);
});

it('reports an unknown provider rather than failing', function (): void {
    config()->set('laranail.captcha.provider', 'not-a-provider');

    $this->artisan('laranail::captcha.install')
        ->expectsOutputToContain('Active provider: unknown.')
        ->assertExitCode(0);
});

it('extends the package-tools install base and keeps both console traits', function (): void {
    expect(is_subclass_of(InstallCommand::class, PackageToolsInstallCommand::class))->toBeTrue();

    $traits = class_uses(InstallCommand::class);

    expect($traits)->toHaveKey(InteractsWithConsoleServices::class)
        ->and($traits)->toHaveKey(InteractsWithConsoleWriter::class);
});
