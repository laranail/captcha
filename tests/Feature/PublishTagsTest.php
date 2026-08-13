<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

/**
 * The publish tags the documentation tells people to type.
 *
 * A wrong tag in a docs page is invisible to every other test and fails only for the reader, who
 * has no way to know whether the tag is wrong or their install is broken. `vendor:publish` with an
 * unknown tag exits successfully and publishes nothing, so it does not even fail loudly.
 *
 * This caught `laranail::captcha-lang`, which the translations page documented and which has never
 * existed — the real tag is `-translations`.
 */
function publishTags(): array
{
    return array_values(array_filter(
        array_keys(ServiceProvider::$publishGroups),
        static fn (mixed $tag): bool => is_string($tag) && str_contains($tag, 'captcha'),
    ));
}

it('registers exactly the tags the package promises', function (): void {
    expect(publishTags())->toEqualCanonicalizing([
        'laranail::captcha-config',
        'laranail::captcha-migrations',
        'laranail::captcha-translations',
        'laranail::captcha-views',
    ]);
});

it('documents only tags that exist', function (): void {
    $root = dirname(__DIR__, 2);

    $files = [$root . '/README.md', ...glob($root . '/docs/*.md'), ...glob($root . '/docs/tools/*.md')];

    $documented = [];

    foreach ($files as $file) {
        preg_match_all('/laranail::captcha-[a-z-]+/', (string) file_get_contents($file), $matches);
        $documented = [...$documented, ...$matches[0]];
    }

    // Not "the docs mention every tag" — several are internal. Only that nothing named in the docs
    // is a tag `vendor:publish` would silently ignore.
    expect(array_diff(array_unique($documented), publishTags()))->toBe([]);
});

it('publishes tags the install command actually uses', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Commands/InstallCommand.php');

    preg_match_all('/laranail::captcha-[a-z-]+/', $source, $matches);

    expect($matches[0])->not->toBeEmpty()
        ->and(array_diff(array_unique($matches[0]), publishTags()))->toBe([]);
});
