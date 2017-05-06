<?php

namespace Platformsh\Cli\Util;

class OsUtil
{
    /**
     * @return bool
     */
    public static function isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @return bool
     */
    public static function isOsX()
    {
        return stripos(PHP_OS, 'Darwin') !== false;
    }
}
