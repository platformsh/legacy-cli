<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->notPath([
        'config/cache/container.php', // Ignore generated file
        'dist/installer.php', // Keep old PHP compatibility
        'tests/data', // Ignore test data
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder)
;
