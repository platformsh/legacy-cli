<?php

namespace Platformsh\Cli\Util;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\VoidCache;

class CacheUtil
{
    protected static $cacheDir;
    protected static $cache;

    /**
     * @param string $cacheDir
     */
    public static function setCacheDir($cacheDir)
    {
        self::$cacheDir = $cacheDir;
    }

    /**
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public static function getCache()
    {
        if (!isset(self::$cache)) {
            self::$cache = getenv('PLATFORMSH_CLI_DISABLE_CACHE') ? new VoidCache() : new FilesystemCache(self::$cacheDir);
        }

        return self::$cache;
    }
}
