<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->notPath([
        'config/cache/container.php', // Ignore generated file
        'dist/installer.php', // Keep old PHP compatibility
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
    ])
    ->setFinder($finder)
;
