<?php

declare(strict_types=1);

// PER Coding Style, with the project's 2-space indentation
return (new PhpCsFixer\Config())
  ->setRiskyAllowed(true)
  ->setIndent('  ')
  ->setRules([
    '@PER-CS2.0' => true,
    'declare_strict_types' => true,
    'no_unused_imports' => true,
    'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    'single_quote' => true,
    'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
  ])
  ->setFinder(
    PhpCsFixer\Finder::create()
      ->in([__DIR__ . '/src', __DIR__ . '/tests'])
      ->append([__DIR__ . '/mygra', __FILE__]),
  );
