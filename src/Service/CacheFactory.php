<?php

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;
use Platformsh\Cli\CliConfig;

class CacheFactory
{

    /**
     * @param \Platformsh\Cli\CliConfig $cliConfig
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public static function createCacheProvider(CliConfig $cliConfig = null)
    {
        $cliConfig = $cliConfig ?: new CliConfig();
        if (!empty($cliConfig->get('api.disable_cache'))) {
            return new VoidCache();
        }

        return new FilesystemCache(
            $cliConfig->getUserConfigDir() . '/cache',
            FilesystemCache::EXTENSION,
            0077 // Remove all permissions from the group and others.
        );
    }
}
