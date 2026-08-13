<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Pinned to the php84 set, matching the ^8.4.1 floor. The pin is the point rather
    // than the level: it stops anything 8.5-only slipping in from a developer's newer
    // runtime, which CI on 8.5 would happily accept and an 8.4 install would not.
    //
    // Note laranail/package-tools deliberately stays on php83 — its floor is lower. Do
    // not copy this line between the two packages without checking the floor.
    ->withSets([LevelSetList::UP_TO_PHP_84])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
    ])
    ->withImportNames(removeUnusedImports: true);
