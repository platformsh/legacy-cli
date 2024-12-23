<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;

class CacheFactory
{
    /**
     * @param Config $cliConfig
     *
     * @return CacheProvider
     */
    public static function createCacheProvider(Config $cliConfig): CacheProvider
    {
        if ($cliConfig->getBool('api.disable_cache')) {
            return new VoidCache();
        }

        return new FilesystemCache(
            $cliConfig->getWritableUserDir() . '/cache',
            FilesystemCache::EXTENSION,
            0o077, // Remove all permissions from the group and others.
        );
    }
}
