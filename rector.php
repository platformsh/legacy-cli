<?php

declare(strict_types=1);

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Rector\InjectCommandServicesRector;
use Rector\Config\RectorConfig;
use Rector\Transform\Rector\MethodCall\MethodCallToPropertyFetchRector;
use Rector\Transform\ValueObject\MethodCallToPropertyFetch;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src/Command',
    ])
    ->withRules([
        InjectCommandServicesRector::class,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withConfiguredRule(MethodCallToPropertyFetchRector::class,
        [new MethodCallToPropertyFetch(CommandBase::class, 'api', 'api')])
    ->withConfiguredRule(MethodCallToPropertyFetchRector::class,
        [new MethodCallToPropertyFetch(CommandBase::class, 'config', 'config')])
    ;
