<?php

namespace Platformsh\Cli\Util;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;

class CacheUtil
{
    protected static $cache;

    /**
     * @param string $cacheDir
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public static function createCache($cacheDir)
    {
        if (!isset(self::$cache)) {
            self::$cache = getenv('PLATFORMSH_CLI_DISABLE_CACHE') ? new VoidCache() : new FilesystemCache($cacheDir);
        }

        return self::$cache;
    }

    /**
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public static function getCache()
    {
        if (!isset(self::$cache)) {
            throw new \BadMethodCallException("Cache not instantiated");
        }

        return self::$cache;
    }
}
