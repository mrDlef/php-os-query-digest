<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Switch_\RemoveDuplicatedCaseInSwitchRector;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

/**
 * Rector is pinned to PHP 7.4 on purpose.
 *
 * The library supports 7.4 → 8.5, so the *lowest* supported version is the one
 * that decides what may be written. Without the pin, the type-declaration set
 * would happily emit union types and make the package uninstallable on the
 * versions the CI matrix still tests.
 *
 * Sets are listed explicitly rather than through withPhpSets()/withPreparedSets():
 * those take named arguments, which are PHP 8.0 syntax — and Rector installs
 * happily on 7.4, so a config using them would be a parse error for anyone
 * running the tool on the lowest supported version.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
        __DIR__ . '/bin/os-query-digest',
    ])
    ->withPhpVersion(PhpVersion::PHP_74)
    ->withSets([
        SetList::PHP_74,
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    // Reuse the existing `use` statements instead of inlining FQCNs in the
    // property types it adds. Positional for the same PHP 7.4 reason as above:
    // (importNames, importDocBlockNames, importShortClasses, removeUnusedImports).
    // Short classes stay unimported — `use Foo;` for a root-namespace class is
    // noise.
    ->withImportNames(true, true, false, true)
    ->withSkip([
        // Infection's own dependencies, installed under tools/ because it
        // needs a newer PHP than this library supports. Not our code to
        // rewrite — and it is written for 8.3, not 7.4.
        __DIR__ . '/tools/infection/vendor',

        // The OpenSearch clients ClientCaptureTest drives, installed under
        // tools/ for the same reason. Somebody else's packages, and they target
        // PHP versions this config does not.
        __DIR__ . '/tools/clients/vendor',

        // `if ($query !== null)` says what it means when the property is
        // `?Node`. Rewriting it to `instanceof Node` asks the reader to know
        // the type before they can read the condition.
        FlipTypeControlToUseExclusiveTypeRector::class,

        // `private static` on a pure helper is documentation: it says the
        // method touches no state. Demoting it to an instance method throws
        // that away to satisfy a metric nobody asked for.
        LocallyCalledStaticMethodToNonStaticRector::class,

        // The `switch` in QueryParser is organised by DSL family, and reads as
        // the list of types the library understands. Merging `fuzzy` into the
        // `match` arm because they happen to share a body would file it under
        // the wrong heading.
        RemoveDuplicatedCaseInSwitchRector::class,
    ]);
