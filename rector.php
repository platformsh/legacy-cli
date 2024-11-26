<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Symfony\Symfony61\Rector\Class_\CommandConfigureToAttributeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        SymfonySetList::SYMFONY_71,
    ])
    ->withRules([
        CommandConfigureToAttributeRector::class,
    ]);
