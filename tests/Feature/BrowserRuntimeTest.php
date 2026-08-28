<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;
use Simtabi\Laranail\Captcha\Services\CaptchaService;
use Simtabi\Laranail\Captcha\Contracts\CredentialStore;

/**
 * Runs the widget in a real browser.
 *
 * `RuntimeScriptTest` executes the same script against a hand-rolled DOM, and that closes the
 * typo-and-syntax class outright. It cannot close two others, and both are load-bearing:
 *
 * - **The MutationObserver never runs.** The harness stubs `observe()` as an empty method, so the
 *   entire re-initialisation path — the half of this package that makes Livewire, `wire:navigate`
 *   and Alpine work — is executed by nothing. It could be deleted and every test would pass.
 * - **The Livewire morph hook never runs.** The harness has no `Livewire`, so the block that keeps
 *   a solved vendor iframe alive across a re-render is likewise unexercised.
 *
 * The harness also builds its DOM by hand, which means it never proves the *rendered* markup is
 * what the script expects: that `data-captcha-config` parses, that the token field really is
 * `{id}-token`, that `aria-describedby` points at an element that exists. Blade could emit
 * something subtly wrong and only a parser would notice.
 *
 * Excluded by default and CI-only, because it needs a browser download. `--group=browser` opts in.
 */
function browserDir(): string
{
    return dirname(__DIR__) . '/Browser';
}

/**
 * Render one page per provider shape, as a visitor would receive it.
 *
 * Written to disk rather than injected with `setContent()` so the browser parses the same bytes
 * Blade produced — the point of the exercise.
 */
function writeFixture(string $name, string $provider, array $credentials): void
{
    config()->set('laranail.captcha.provider', $provider);
    config()->set('laranail.captcha.environments.default.' . $provider, $credentials);

    app()->forgetInstance(CaptchaService::class);
    app()->forgetInstance(CredentialStore::class);

    // `@once` is process-global, so a second render in the same process emits no runtime at all
    // and the fixture would be a page with no script in it.
    app('view')->flushState();

    $body = Blade::render('<form id="f" action="#" method="post"><x-captcha /><button type="submit">Go</button></form>');

    $html = <<<HTML
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>{$name}</title></head>
    <body>{$body}</body></html>
    HTML;

    $dir = browserDir() . '/.tmp';

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir . '/' . $name . '.html', $html);
}

function playwright(): ?Process
{
    if (! is_dir(browserDir() . '/node_modules/@playwright')) {
        return null;
    }

    return new Process(['npx', 'playwright', 'test', '--reporter=list'], browserDir());
}

beforeEach(function (): void {
    if (! playwright() instanceof Process) {
        // Loud, not silent. A green run that never opened a browser is exactly the false
        // confidence this file exists to remove.
        // `npm install`, not `ci` — this family does not commit lock files.
        test()->markTestSkipped(
            'Playwright is not installed. Run `npm --prefix tests/Browser install` '
            . 'then `npx --prefix tests/Browser playwright install chromium`.',
        );
    }
});

/**
 * Record what the fixtures were rendered from, so a stale run cannot pass.
 *
 * Rendering needs Blade, so the HTML has to reach the browser through disk — and a file on disk
 * outlives the template that produced it. `global-setup.mjs` re-hashes these and refuses to run on
 * a mismatch. This is not hypothetical: four deliberate breaks to the runtime were all reported
 * green by a standalone Playwright run before this existed.
 *
 * @param list<string> $pages
 */
function writeManifest(array $pages): void
{
    $sources = [];

    foreach ([
        'resources/views/components/captcha.blade.php',
        'src/View/Components/Captcha.php',
    ] as $file) {
        $sources[$file] = hash_file('sha256', dirname(__DIR__, 2) . '/' . $file);
    }

    file_put_contents(
        browserDir() . '/.tmp/manifest.json',
        (string) json_encode(['pages' => $pages, 'sources' => $sources], JSON_PRETTY_PRINT),
    );
}

it('drives the rendered widget in a real browser', function (): void {
    // Three shapes, because the runtime branches on all three: a server-rendered challenge with no
    // vendor script, a hosted widget holding live state, and a provider with nothing to click.
    writeFixture('math', 'math', []);
    writeFixture('turnstile', 'turnstile', ['site_key' => 'site-key-abc', 'secret' => 'secret-key']);
    writeFixture('recaptcha-v3', 'recaptcha-v3', ['site_key' => 'site-key-abc', 'secret' => 'secret-key']);

    writeManifest(['math', 'turnstile', 'recaptcha-v3']);

    $process = playwright();
    $process->setTimeout(300);
    $process->run();

    expect($process->getExitCode())->toBe(
        0,
        $process->getOutput() . $process->getErrorOutput(),
    );
})->group('browser');
