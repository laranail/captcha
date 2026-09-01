<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;
use Simtabi\Laranail\Captcha\Enums\Provider;

/**
 * Everything the repo names must exist.
 *
 * Four false claims shipped in v0.1.0 — a `suggest` entry advertising a runtime requirement that
 * did not exist, a docblock citing tests that were never written, a CI flag excluding nothing, and
 * a documented `--group=live` matching no test. They share one shape: **prose asserting something,
 * with nothing checking the assertion.** All four were found by reading a summary sceptically,
 * which is luck rather than process.
 *
 * This is the process. Each assertion below is one way the repo can name something that is not
 * there, and every one of them is a failure only a reader would otherwise hit — `vendor:publish`
 * with an unknown tag exits successfully and publishes nothing, and `pest --group=typo` exits
 * successfully having run nothing.
 *
 * Not covered here, deliberately: prose claims like "asserted by" or "the suite checks". Those
 * cannot be checked mechanically. They were swept once by hand; this file exists so the mechanical
 * half never needs sweeping again.
 */
function docFiles(): array
{
    $root = dirname(__DIR__, 2);

    return [
        $root.'/README.md',
        ...glob($root.'/docs/*.md'),
        ...glob($root.'/docs/tools/*.md'),
        ...glob($root.'/docs/recipes/*.md'),
    ];
}

function docText(): string
{
    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        docFiles(),
    ));
}

/** @return list<string> */
function matchesIn(string $pattern, string $subject): array
{
    preg_match_all($pattern, $subject, $matches);

    return array_values(array_unique($matches[0]));
}

function publishTags(): array
{
    return array_values(array_filter(
        array_keys(ServiceProvider::$publishGroups),
        static fn (mixed $tag): bool => is_string($tag) && str_contains($tag, 'captcha'),
    ));
}

it('registers exactly the publish tags it promises', function (): void {
    expect(publishTags())->toEqualCanonicalizing([
        'laranail::captcha-config',
        'laranail::captcha-migrations',
        'laranail::captcha-translations',
        'laranail::captcha-views',
    ]);
});

it('names no publish tag that does not exist', function (): void {
    // Caught `laranail::captcha-lang`, which the translations page documented and which has never
    // existed.
    expect(array_diff(matchesIn('/laranail::captcha-[a-z-]+/', docText()), publishTags()))->toBe([]);
});

it('names no publish tag the install command cannot use', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Commands/InstallCommand.php');

    expect(array_diff(matchesIn('/laranail::captcha-[a-z-]+/', $source), publishTags()))->toBe([]);
});

it('names no Artisan command that does not resolve', function (): void {
    $registered = array_keys(Artisan::all());

    $documented = matchesIn('/laranail::captcha\.[a-z-]+/', docText());

    expect($documented)->not->toBeEmpty()
        ->and(array_diff($documented, $registered))->toBe([]);
});

it('names no provider that is not a case of the enum', function (): void {
    $cases = array_map(static fn (Provider $p): string => $p->value, Provider::cases());

    // Scoped to the fenced `CAPTCHA_PROVIDER=` lines and the providers table, because prose uses
    // the same words as ordinary English — "math", "null" — and matching those would be noise.
    $documented = matchesIn('/(?<=^\| `)[a-z0-9-]+(?=`)/m', (string) file_get_contents(
        dirname(__DIR__, 2).'/docs/providers.md',
    ));

    expect($documented)->not->toBeEmpty()
        ->and(array_diff($documented, $cases))->toBe([]);
});

it('documents every provider the enum offers', function (): void {
    $providers = (string) file_get_contents(dirname(__DIR__, 2).'/docs/providers.md');

    // The other direction. A provider added to the enum and never written up is invisible to
    // anyone choosing one, which is the only moment the list matters.
    foreach (Provider::cases() as $provider) {
        expect($providers)->toContain('`'.$provider->value.'`');
    }
});

it('names no config key that does not exist', function (): void {
    $config = config('laranail.captcha');

    $documented = array_map(
        static fn (string $key): string => substr($key, strlen('laranail.captcha.')),
        matchesIn('/laranail\.captcha\.[a-z_.]+[a-z_]/', docText()),
    );

    expect($documented)->not->toBeEmpty();

    foreach ($documented as $key) {
        expect(data_get($config, $key, '__missing__'))->not->toBe(
            '__missing__',
            "The docs name config key [laranail.captcha.{$key}], which config/captcha.php does not define.",
        );
    }
});

it('names no test group that matches nothing', function (): void {
    $root = dirname(__DIR__, 2);

    // `glob()` does not recurse — `tests/**/*.php` silently misses `tests/Feature/Live/`, which is
    // exactly where the `live` group lives. An iterator, so adding a directory cannot quietly
    // narrow what this checks.
    $tagged = '';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $tagged .= (string) file_get_contents($file->getPathname());
        }
    }

    $named = array_map(
        static fn (string $flag): string => (string) preg_replace('/.*=/', '', $flag),
        [
            ...matchesIn('/--group=[a-z,]+/', docText()),
            ...matchesIn('/--exclude-group=[a-z,]+/', (string) file_get_contents($root.'/.github/workflows/tests.yml')),
        ],
    );

    expect($named)->not->toBeEmpty();

    foreach (array_unique(explode(',', implode(',', $named))) as $group) {
        // A CI flag excluding a group nothing carries is a job that passes while proving nothing,
        // which is precisely how `--exclude-group=altcha` sat for a while.
        //
        // `Assert` rather than `expect()->toContain()`: that expectation takes a *list of needles*,
        // so a message passed as a second argument becomes a second required substring. This test
        // failed on exactly that before anyone read the message it was supposedly printing.
        Assert::assertStringContainsString(
            "'".$group."'",
            $tagged,
            "No test carries the [{$group}] group, but the docs or CI name it.",
        );
    }
});
