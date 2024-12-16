<?php

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
        if ($cliConfig->getWithDefault('api.disable_cache', false)) {
            return new VoidCache();
        }

        return new FilesystemCache(
            $cliConfig->getWritableUserDir() . '/cache',
            FilesystemCache::EXTENSION,
            0077 // Remove all permissions from the group and others.
        );
    }
}
