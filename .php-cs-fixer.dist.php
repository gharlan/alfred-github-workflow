<?php

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'empty_loop_body' => ['style' => 'semicolon'],
        'method_argument_space' => false,
        'native_constant_invocation' => false,
        'psr_autoloading' => false,
        'no_useless_else' => true,
    ])
    ->setFinder((new PhpCsFixer\Finder())->in(__DIR__))
;
