<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class Filesystem
{
    protected Shell $shell;
    protected SymfonyFilesystem $fs;

    protected bool $relative = false;
    protected bool $copyOnWindows = false;

    /**
     * @param Shell|null             $shell
     * @param SymfonyFilesystem|null $fs
     */
    public function __construct(?Shell $shell = null, ?SymfonyFilesystem $fs = null)
    {
        $this->shell = $shell ?: new Shell();
        $this->fs = $fs ?: new SymfonyFilesystem();
    }

    public function setCopyOnWindows(bool $copyOnWindows = true): void
    {
        $this->copyOnWindows = $copyOnWindows;
    }

    /**
     * Sets whether to use relative links.
     */
    public function setRelativeLinks(bool $relative = true): void
    {
        // This is not possible on Windows.
        if (OsUtil::isWindows()) {
            $relative = false;
        }
        $this->relative = $relative;
    }

    /**
     * Delete a file or directory.
     *
     * @param string|iterable $files
     *   A filename or an iterable list of files to delete.
     * @param bool $retryWithChmod
     *   Whether to retry deleting on error, after recursively changing file
     *   modes to add read/write/exec permissions. A bit like 'rm -rf'.
     *
     * @return bool
     */
    public function remove(string|iterable $files, bool $retryWithChmod = false): bool
    {
        try {
            $this->fs->remove($files);
        } catch (IOException $e) {
            if ($retryWithChmod && $this->unprotect($files, true)) {
                return $this->remove($files, false);
            }
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Make files writable by the current user.
     *
     * @param string|iterable $files
     *   A filename or an iterable list of files.
     * @param bool $recursive
     *   Whether to change the mode recursively or not.
     *
     * @return bool
     *   True on success, false on failure.
     */
    protected function unprotect(string|iterable $files, bool $recursive = false): bool
    {
        if (!$files instanceof \Traversable) {
            $files = new \ArrayObject(is_array($files) ? $files : [$files]);
        }

        foreach ($files as $file) {
            if (is_link($file)) {
                continue;
            } elseif (is_dir($file)) {
                if ((!is_executable($file) || !is_writable($file))
                    && true !== @chmod($file, 0o700)) {
                    return false;
                }
                if ($recursive && !$this->unprotect(new \FilesystemIterator($file), true)) {
                    return false;
                }
            } elseif (!is_writable($file) && true !== @chmod($file, 0o600)) {
                return false;
            }
        }

        return true;
    }

    public function mkdir(string $dir, int $mode = 0o755): void
    {
        $this->fs->mkdir($dir, $mode);
    }

    /**
     * Copies a file, if it is newer than the destination.
     */
    public function copy(string $source, string $destination, bool $override = false): void
    {
        if (is_dir($destination) && !is_dir($source)) {
            $destination = rtrim($destination, '/') . '/' . basename($source);
        }
        $this->fs->copy($source, $destination, $override);
    }

    /**
     * Copies all files and folders between directories.
     *
     * @param string[] $skip Paths to skip.
     */
    public function copyAll(string $source, string $destination, array $skip = ['.git', '.DS_Store'], bool $override = false): void
    {
        if (is_dir($source) && !is_dir($destination)) {
            if (!mkdir($destination, 0o755, true)) {
                throw new \RuntimeException("Failed to create directory: " . $destination);
            }
        }

        if (is_dir($source)) {
            // Prevent infinite recursion when the destination is inside the
            // source.
            if (str_starts_with($destination, $source)) {
                $relative = str_replace($source, '', $destination);
                $parts = explode('/', ltrim($relative, '/'), 2);
                $skip[] = $parts[0];
            }

            $sourceDirectory = opendir($source);
            if (!$sourceDirectory) {
                throw new \RuntimeException("Failed to open directory: " . $source);
            }
            while ($file = readdir($sourceDirectory)) {
                // Skip symlinks, '.' and '..', and files in $skip.
                if ($file === '.'
                    || $file === '..'
                    || $this->fileInList($file, $skip)
                    || is_link($source . '/' . $file)) {
                    continue;
                }

                // Recurse into directories.
                if (is_dir($source . '/' . $file)) {
                    $this->copyAll($source . '/' . $file, $destination . '/' . $file, $skip, $override);
                    continue;
                }

                // Perform the copy.
                if (is_file($source . '/' . $file)) {
                    $this->fs->copy($source . '/' . $file, $destination . '/' . $file, $override);
                }
            }
            closedir($sourceDirectory);
        } else {
            $this->fs->copy($source, $destination, $override);
        }
    }

    /**
     * Create a symbolic link to a file or directory.
     *
     * @param string $target The target to link to (must already exist).
     * @param string $link   The name of the symbolic link.
     */
    public function symlink(string $target, string $link): void
    {
        if (!file_exists($target)) {
            throw new \InvalidArgumentException('Target not found: ' . $target);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, dirname($link));
        }
        $this->fs->symlink($target, $link, $this->copyOnWindows);
    }

    /**
     * Wraps Symfony Filesystem's makePathRelative() with enhancements.
     *
     * This ensures both parts of the path are realpaths, if possible, before
     * calculating the relative path. It also trims trailing slashes.
     *
     * @param string $path      An absolute path.
     * @param string $reference The path to which it will be made relative.
     *
     * @return string
     *   The $path, relative to the $reference.
     *
     * @see SymfonyFilesystem::makePathRelative()
     */
    public function makePathRelative(string $path, string $reference): string
    {
        $path = realpath($path) ?: $path;
        $reference = realpath($reference) ?: $reference;

        return rtrim($this->fs->makePathRelative($path, $reference), DIRECTORY_SEPARATOR);
    }

    /**
     * Formats a path for display (uses the relative path if it's simpler).
     */
    public function formatPathForDisplay(string $path): string
    {
        $relative = $this->makePathRelative($path, (string) getcwd());
        if (!str_contains($relative, '../..') && strlen($relative) < strlen($path)) {
            return $relative;
        }

        return rtrim(trim($path), '/');
    }

    /**
     * Check if a filename is in a list.
     *
     * @param string $filename
     * @param string[] $list
     *
     * @return bool
     */
    protected function fileInList(string $filename, array $list): bool
    {
        foreach ($list as $pattern) {
            if (fnmatch($pattern, $filename, FNM_PATHNAME | FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Symlink or copy all files and folders between two directories.
     *
     * @param string $source
     * @param string   $destination
     * @param bool $skipExisting
     * @param bool $recursive
     * @param string[] $exclude
     * @param bool $copy
     *
     * @throws \Exception When a conflict is discovered.
     */
    public function symlinkAll(
        string $source,
        string $destination,
        bool   $skipExisting = true,
        bool   $recursive = false,
        array  $exclude = [],
        bool $copy = false,
    ): void {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $absPath = realpath($source);
        if (!$absPath) {
            throw new \RuntimeException('Failed to resolve path: ' . $source);
        }

        // Files to always skip.
        $skip = ['.git', '.DS_Store'];
        $skip = array_merge($skip, $exclude);

        $sourceDirectory = opendir($absPath);
        if (!$sourceDirectory) {
            throw new \RuntimeException('Failed to open directory: ' . $absPath);
        }
        while ($file = readdir($sourceDirectory)) {
            // Skip symlinks, '.' and '..', and files in $skip.
            if ($file === '.' || $file === '..' || $this->fileInList($file, $skip) || is_link($absPath . '/' . $file)) {
                continue;
            }
            $sourceFile = $absPath . '/' . $file;
            $linkFile = $destination . '/' . $file;

            if ($recursive && !is_link($linkFile) && is_dir($linkFile) && is_dir($sourceFile)) {
                $this->symlinkAll($sourceFile, $linkFile, $skipExisting, $recursive, $exclude, $copy);
                continue;
            } elseif (file_exists($linkFile)) {
                if ($skipExisting) {
                    continue;
                } else {
                    throw new \Exception('File exists: ' . $linkFile);
                }
            } elseif (is_link($linkFile)) {
                // This is a broken link. Remove it.
                $this->remove($linkFile);
            }

            if ($copy) {
                $this->copyAll($sourceFile, $linkFile, $exclude);
            } else {
                $this->symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Makes a relative path into an absolute one.
     *
     * The realpath() function will only work for existing files, and not for
     * symlinks. This is a more flexible solution.
     *
     * @throws \InvalidArgumentException If the parent directory is not found.
     */
    public function makePathAbsolute(string $relativePath): string
    {
        if (file_exists($relativePath) && !is_link($relativePath) && ($realPath = realpath($relativePath))) {
            $absolute = $realPath;
        } else {
            $parent = dirname($relativePath);
            if (!is_dir($parent) || !($parentRealPath = realpath($parent))) {
                throw new \InvalidArgumentException('Directory not found: ' . $parent);
            }
            $basename = basename($relativePath);
            $absolute = $basename == '..'
                ? dirname($parentRealPath)
                : rtrim($parentRealPath . '/' . $basename, './');
        }

        return $absolute;
    }

    /**
     * Tests whether a file or directory is writable even if it does not exist.
     */
    public function canWrite(string $name): bool
    {
        if (is_writable($name)) {
            return true;
        }

        $current = $name;
        while (!file_exists($current) && ($parent = dirname($current)) && $parent !== $current) {
            if (is_writable($parent)) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Writes a file and creates a backup if the contents have changed.
     */
    public function writeFile(string $filename, string $contents, bool $backup = true): void
    {
        $fs = new SymfonyFilesystem();
        if (file_exists($filename) && $backup && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . basename($filename) . '.bak';
            $fs->rename($filename, $backupName, true);
        }
        $fs->dumpFile($filename, $contents);
    }

    /**
     * Creates a gzipped tar archive of a directory's contents.
     */
    public function archiveDir(string $dir, string $destination): void
    {
        $tar = $this->getTarExecutable();
        $dir = $this->fixTarPath($dir);
        $destination = $this->fixTarPath($destination);
        $this->shell->execute([$tar, '-czp', '-C' . $dir, '-f' . $destination, '.'], null, true);
    }

    /**
     * Extracts a gzipped tar archive into the specified destination directory.
     */
    public function extractArchive(string $archive, string $destination): void
    {
        if (!file_exists($archive)) {
            throw new \InvalidArgumentException("Archive not found: $archive");
        }
        if (!file_exists($destination) && !mkdir($destination, 0o755, true)) {
            throw new \InvalidArgumentException("Could not create destination directory: $destination");
        }
        $tar = $this->getTarExecutable();
        $destination = $this->fixTarPath($destination);
        $archive = $this->fixTarPath($archive);
        $this->shell->execute([$tar, '-xzp', '-C' . $destination, '-f' . $archive], null, true);
    }

    /**
     * Fixes a path so that it can be used with tar on Windows.
     *
     * @see http://betterlogic.com/roger/2009/01/tar-woes-with-windows/
     */
    protected function fixTarPath(string $path): string
    {
        if (OsUtil::isWindows()) {
            $path = preg_replace_callback(
                '#^([A-Z]):/#i',
                fn(array $matches): string => '/' . strtolower((string) $matches[1]) . '/',
                str_replace('\\', '/', $path),
            );
        }

        return $path;
    }

    /**
     * @return string
     */
    protected function getTarExecutable(): string
    {
        $candidates = ['tar', 'tar.exe', 'bsdtar.exe'];
        foreach ($candidates as $command) {
            if ($this->shell->commandExists($command)) {
                return $command;
            }
        }
        throw new \RuntimeException("Tar command not found");
    }

    /**
     * Validates a directory.
     */
    public function validateDirectory(string $directory, bool $writable = false): void
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not found: %s', $directory));
        } elseif (!is_readable($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not readable: %s', $directory));
        } elseif ($writable && !is_writable($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not writable: %s', $directory));
        }
    }
}
