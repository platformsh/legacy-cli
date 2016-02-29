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
            if (getenv(CLI_ENV_PREFIX . 'DISABLE_CACHE')) {
                self::$cache = new VoidCache();
            }
            else {
                // Remove permissions from the group and others.
                $umask = 0077;
                self::$cache = new FilesystemCache(self::$cacheDir, FilesystemCache::EXTENSION, $umask);
            }
        }

        return self::$cache;
    }
}
