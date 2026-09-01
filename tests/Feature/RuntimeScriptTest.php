<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Symfony\Component\Process\Process;

/**
 * Runs the emitted JavaScript, rather than asserting it was emitted.
 *
 * Every other client-side test in this suite checks that the script *contains* the right things.
 * That is a real gap and it was documented as one: a typo inside the IIFE, a reference to a
 * variable that does not exist, a missing brace — all of it passes a string assertion and fails
 * only in a visitor's browser, where nothing reports it.
 *
 * Two levels, cheapest first. `node --check` parses the script, which catches the typo class
 * outright. Then a hand-rolled DOM harness executes it and exercises the behaviour that matters:
 * that it initialises, that an expired token is cleared, and that the vendor widget is reset.
 *
 * No jsdom, no npm install, no build step — the runtime touches about a dozen DOM APIs and
 * stubbing them is cheaper to keep than a JavaScript toolchain inside a PHP package. Skipped, and
 * loudly, where Node is absent.
 */
function extractRuntime(): string
{
    config()->set('laranail.captcha.provider', 'turnstile');
    config()->set('laranail.captcha.environments.default.turnstile', [
        'site_key' => 'site-key-abc',
        'secret' => 'secret-key',
    ]);

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);
    app('view')->flushState();

    $rendered = Blade::render('<form><x-captcha /></form>');

    // The runtime is the last script block, and the only one without a src.
    preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/s', $rendered, $matches);

    return html_entity_decode((string) end($matches[1]), ENT_QUOTES);
}

function node(): ?string
{
    foreach (['node', '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $candidate) {
        $probe = new Process([$candidate, '--version']);
        $probe->run();

        if ($probe->isSuccessful()) {
            return $candidate;
        }
    }

    return null;
}

beforeEach(function (): void {
    if (node() === null) {
        // Skipped with a reason rather than silently, because a green run that never executed the
        // script is exactly the false confidence this file exists to remove.
        test()->markTestSkipped('Node is not available, so the widget runtime cannot be executed.');
    }
});

it('parses as valid JavaScript', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'captcha-runtime').'.js';
    file_put_contents($file, extractRuntime());

    $process = new Process([(string) node(), '--check', $file]);
    $process->run();

    @unlink($file);

    // The named risk, closed. A syntax error here is invisible to every string assertion in the
    // suite and fatal in a browser.
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('initialises, clears an expired token and resets the widget', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'captcha-runtime').'.js';
    file_put_contents($file, extractRuntime());

    $process = new Process([(string) node(), dirname(__DIR__).'/js/harness.mjs', $file]);
    $process->run();

    @unlink($file);

    expect($process->getExitCode())->toBe(
        0,
        $process->getErrorOutput().$process->getOutput(),
    );
});
