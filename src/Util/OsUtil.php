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

    /**
     * @return bool
     */
    public static function isLinux()
    {
        return stripos(PHP_OS, 'Linux') !== false;
    }

    /**
     * Escapes a shell argument for POSIX shells, even when run on Windows.
     *
     * PHP's escapeshellarg() function adapts its output depending on the
     * system. So to escape arguments consistently for remote non-Windows
     * systems, we need our own method.
     *
     * @param string $arg
     *
     * @return string
     */
    public static function escapePosixShellArg($arg)
    {
        // Skip quoting the argument if it only contains safe characters.
        // This uses a fairly conservative allow-list.
        if (preg_match('/^[a-z0-9_.@%:-]+$/i', $arg) === 1) {
            return $arg;
        }
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * Escapes a shell argument, with Windows compatibility.
     *
     * @see \Symfony\Component\Process\Process::escapeArgument()
     *
     * @param string $argument
     *
     * @return string
     */
    public static function escapeShellArg($argument)
    {
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            return self::escapePosixShellArg($argument);
        }
        if ('' === $argument = (string) $argument) {
            return '""';
        }
        if (false !== strpos($argument, "\0")) {
            $argument = str_replace("\0", '?', $argument);
        }
        if (!preg_match('/[\/()%!^"<>&|\s]/', $argument)) {
            return $argument;
        }
        $argument = preg_replace('/(\\\\+)$/', '$1$1', $argument);

        return '"' . str_replace(['"', '^', '%', '!', "\n"], ['""', '"^^"', '"^%"', '"^!"', '!LF!'], $argument) . '"';
    }

    /**
     * Finds all executable matching the given name inside the PATH.
     *
     * @see \Symfony\Component\Process\ExecutableFinder::find()
     *
     * @param string $name
     *
     * @return array
     */
    public static function findExecutables($name)
    {
        $dirs = explode(\PATH_SEPARATOR, getenv('PATH') ?: getenv('Path'));
        $suffixes = [''];

        $found = [];

        $isWindows = self::isWindows();
        if ($isWindows) {
            $suffixes = ['.exe', '.bat', '.cmd', '.com'];
            $pathExt = getenv('PATHEXT');
            $suffixes = array_merge($pathExt ? explode(\PATH_SEPARATOR, $pathExt) : $suffixes, $suffixes);
        }

        foreach ($suffixes as $suffix) {
            foreach ($dirs as $dir) {
                if (@is_file($file = $dir.\DIRECTORY_SEPARATOR.$name.$suffix) && ($isWindows || @is_executable($file))) {
                    array_push($found, $file);
                }
            }
        }

        return $found;
    }
}
