<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    // Appended, not found: the composer bin has no .php extension.
    ->append([__DIR__ . '/bin/os-query-digest', __DIR__ . '/rector.php', __FILE__]);

/**
 * PER-CS is the baseline — it is what PSR-12 became — plus the rules that catch
 * the things review keeps having to say.
 *
 * Deliberately *not* the @PhpCsFixer preset: it pulls in @Symfony, and with it
 * Yoda conditions and class-element reordering. Those are house style, not
 * quality, and they are not this house's.
 */
return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,

        // Every file already declares it; this keeps it that way.
        'declare_strict_types' => true,

        // The library compares untrusted decoded JSON. A loose comparison there
        // is a bug waiting for the right input.
        'strict_comparison' => true,
        'strict_param' => true,

        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        // Root-namespace classes stay inline as `\stdClass`. Importing them adds
        // a `use` line per one-off, and it would fight Rector, which is
        // configured not to import them either.
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        // Off: some assignments exist only to carry a `/** @var */` that tells
        // the analyser the shape of decoded JSON. Collapsing them into the
        // return strands the annotation and loses the type.
        'return_assignment' => false,
        'simplified_null_return' => false,
        'void_return' => true,
        'native_function_casing' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Docblocks: tidy them, but never turn them into plain comments —
        // `/** @var array<string,mixed> $x */` before an assignment is how the
        // analyser is told what it cannot infer.
        'phpdoc_to_comment' => false,
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'no_empty_phpdoc' => true,
        'no_blank_lines_after_phpdoc' => true,

        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
    ]);
