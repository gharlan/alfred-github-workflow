<?php

return (new PhpCsFixer\Config())
    ->setCacheFile('.cache/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x2Migration:risky' => true,

        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => ['strategy' => 'remove'],
        'empty_loop_body' => ['style' => 'semicolon'],
        'method_argument_space' => false,
        'native_constant_invocation' => false,
        'no_null_property_initialization' => false,
        'phpdoc_align' => false,
        'phpdoc_line_span' => [
            'class' => 'single',
            'trait_import' => 'single',
            'const' => 'single',
            'case' => 'single',
            'property' => 'single',
            'method' => 'single',
            'other' => 'single',
        ],
        'phpdoc_separation' => false,
        'psr_autoloading' => false,
        'single_line_empty_body' => true,
        'use_arrow_functions' => false,
        'void_return' => false,
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in(__DIR__)
            ->append(['bin/build'])
    )
;
