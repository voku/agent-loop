<?php

declare(strict_types=1);

/*
 * Code style is a CI decision, not guidance an agent has to read: `composer cs`
 * fails on drift and `composer cs:fix` repairs it without a model in the loop.
 * PER-CS 2.0 plus the two conventions this codebase already uses consistently
 * (`fn (`, multi-line empty bodies), so the gate encodes the existing style
 * instead of rewriting it.
 */
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__ . '/build/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS2.0' => true,
        'function_declaration' => ['closure_fn_spacing' => 'one'],
        'single_line_empty_body' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/phpstan',
                __DIR__ . '/src',
                __DIR__ . '/tests',
                __DIR__ . '/tools/Context',
                __DIR__ . '/tools/Dogfood',
            ])
            // Deliberately broken code the project PHPStan rules must reject.
            ->exclude('PHPStan/data')
            ->name('*.php')
            ->append([__DIR__ . '/bin/agent-loop', __FILE__]),
    );
