<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/packages/*/src',
        __DIR__ . '/packages/*/tests',
        __DIR__ . '/tests',
    ])
    ->exclude('vendor')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP84Migration' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'no_whitespace_in_blank_line' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_leading_import_slash' => true,
        'no_trailing_comma_in_singleline' => true,
        'single_line_empty_body' => true,
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
    ])
    ->setFinder($finder);
