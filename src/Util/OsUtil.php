<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class OsUtil
{
    /**
     * @return bool
     */
    public static function isWindows(): bool
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }

    /**
     * @return bool
     */
    public static function isOsX(): bool
    {
        return stripos(PHP_OS, 'Darwin') !== false;
    }

    /**
     * @return bool
     */
    public static function isLinux(): bool
    {
        return stripos(PHP_OS, 'Linux') !== false;
    }

    /**
     * Escapes a shell argument for POSIX shells, even when run on Windows.
     *
     * PHP's escapeshellarg() function adapts its output depending on the
     * system. So to escape arguments consistently for remote non-Windows
     * systems, we need our own method.
     */
    public static function escapePosixShellArg(string $arg): string
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
     */
    public static function escapeShellArg(string $argument): string
    {
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            return self::escapePosixShellArg($argument);
        }
        if ('' === $argument) {
            return '""';
        }
        if (str_contains($argument, "\0")) {
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
     * @return string[]
     */
    public static function findExecutables(string $name): array
    {
        $dirs = explode(\PATH_SEPARATOR, (string) (getenv('PATH') ?: getenv('Path')));
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
                if (@is_file($file = $dir . \DIRECTORY_SEPARATOR . $name . $suffix) && ($isWindows || @is_executable($file))) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }
}
