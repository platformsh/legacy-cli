<?php

declare(strict_types=1);

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Rector\InjectCommandServicesRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Symfony61\Rector\Class_\CommandConfigureToAttributeRector;
use Rector\Transform\Rector\MethodCall\MethodCallToPropertyFetchRector;
use Rector\Transform\ValueObject\MethodCallToPropertyFetch;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRules([
        CommandConfigureToAttributeRector::class,
        InjectCommandServicesRector::class,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withConfiguredRule(MethodCallToPropertyFetchRector::class,
        [new MethodCallToPropertyFetch(CommandBase::class, 'api', 'api')])
    ->withConfiguredRule(MethodCallToPropertyFetchRector::class,
        [new MethodCallToPropertyFetch(CommandBase::class, 'config', 'config')])
    ->withSets([
        SetList::PHP_74,
        SetList::PHP_80,
        SetList::PHP_81,
        SetList::PHP_82,
        SetList::TYPE_DECLARATION,
    ])
    ;
