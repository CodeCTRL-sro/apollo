<?php

declare(strict_types=1);

/**
 * Coding standard for Apollo.
 *
 * Nothing has been reformatted yet: the rules are configured, but the sweep is a
 * separate, deliberate commit. Run it when you are ready for a large mechanical diff:
 *
 *     composer cs:fix
 *
 * Until then `composer cs` reports what would change, and CI runs it as an advisory
 * step rather than a blocking one — see .github/workflows/ci.yml.
 *
 * The codebase currently mixes array() and [] (roughly 457 to 70 in favour of the long
 * form). The rule below standardises on the short form, which is what PSR-12 era code,
 * Laravel and Symfony all use; that is the bulk of the eventual diff.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(array(__DIR__ . '/src', __DIR__ . '/tests'))
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules(array(
        '@PSR12' => true,
        '@PHP83Migration' => true,

        'array_syntax' => array('syntax' => 'short'),
        'list_syntax' => array('syntax' => 'short'),
        'trailing_comma_in_multiline' => array('elements' => array('arrays', 'arguments', 'parameters')),

        // Import hygiene. The mixed ordering today makes it hard to see at a glance what
        // a class actually depends on.
        'ordered_imports' => array('sort_algorithm' => 'alpha'),
        'no_unused_imports' => true,
        'global_namespace_import' => array(
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ),

        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => array(
            'allow_mixed' => true,
            'remove_inheritdoc' => false,
        ),
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
        'phpdoc_summary' => false,
        'no_empty_phpdoc' => true,
        'no_empty_comment' => true,

        'blank_line_before_statement' => array('statements' => array('return', 'throw', 'try')),
        'cast_spaces' => array('space' => 'none'),
        'concat_space' => array('spacing' => 'one'),
        'native_function_invocation' => false,

        // Risky, but both are correctness rules rather than cosmetics.
        'strict_comparison' => false,
        'modernize_strpos' => true,
    ));
