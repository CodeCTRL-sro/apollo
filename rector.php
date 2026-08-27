<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Automated modernisation for Apollo.
 *
 * Configured but not yet applied — like the coding standard, the sweep is its own
 * commit. Preview it with `composer rector`, apply it with `composer rector:fix`.
 *
 * What the enabled sets will mostly do here:
 *  - promote constructor properties (nearly every class assigns them by hand today)
 *  - add native parameter and return types where the docblock already states them,
 *    then drop the docblock line that only repeated the type
 *  - replace `$x = $y ? $y : $z` style code with ?? / ?:
 *  - turn multi-branch switches into match where the branches only return
 *
 * Deliberately NOT enabled:
 *  - SetList::TYPE_DECLARATION in its strictest form, which adds types inferred from
 *    usage rather than from a declaration. On a library that is how you accidentally
 *    narrow a public signature and break a consumer.
 *  - PRIVATIZATION, which can reduce the visibility of methods an application overrides.
 */
return RectorConfig::configure()
    ->withPaths(array(
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ))
    ->withSets(array(
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
    ))
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip(array(
        // Generated output, not hand maintained.
        __DIR__ . '/src/Database/Doctrine/Console/GenerateEntitiesFromDatabaseCommand.php',
    ));
