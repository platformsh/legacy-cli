<?php

namespace Platformsh\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FilesystemHelper extends Helper
{

    protected $relative = false;
    protected $fs;
    protected $copyOnWindows = false;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    public function getName()
    {
        return 'fs';
    }

    /**
     * @param ShellHelperInterface $shellHelper
     * @param object               $fs
     */
    public function __construct(ShellHelperInterface $shellHelper = null, $fs = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
        $this->fs = $fs ?: new Filesystem();
        $this->copyOnWindows = (bool) getenv(CLI_ENV_PREFIX . 'COPY_ON_WINDOWS');
    }

    /**
     * Set whether to use relative links.
     *
     * @param bool $relative
     */
    public function setRelativeLinks($relative = true)
    {
        // This is not possible on Windows.
        if ($this->isWindows()) {
            $relative = false;
        }
        $this->relative = $relative;
    }

    /**
     * Delete a file or directory.
     *
     * @param string $filename
     *
     * @return bool
     */
    public function remove($filename)
    {
        try {
            $this->fs->remove($filename);
        } catch (IOException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            return false;
        }

        return true;
    }

    /**
     * @return string The absolute path to the user's home directory.
     */
    public function getHomeDirectory()
    {
        $home = getenv('HOME');
        if (empty($home)) {
            // Windows compatibility.
            if ($userProfile = getenv('USERPROFILE')) {
                $home = $userProfile;
            } elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
                $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            }
        }

        return $home;
    }

    /**
     * Copy a file, if it is newer than the destination.
     *
     * @param string $source
     * @param string $destination
     * @param bool   $override
     */
    public function copy($source, $destination, $override = false)
    {
        if (is_dir($destination) && !is_dir($source)) {
            $destination = rtrim($destination, '/') . '/' . basename($source);
        }
        $this->fs->copy($source, $destination, $override);
    }

    /**
     * Copy all files and folders between directories.
     *
     * @param string $source
     * @param string $destination
     * @param array $skip
     */
    public function copyAll($source, $destination, array $skip = [])
    {
        if (is_dir($source) && !is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: " . $destination);
            }
        }

        if (is_dir($source)) {
            $skip[] = '.';
            $skip[] = '..';
            $skip[] = '.git';
            $skip[] = CLI_PROJECT_CONFIG_DIR;

            // Prevent infinite recursion when the destination is inside the
            // source.
            if (strpos($destination, $source) === 0) {
                $relative = str_replace($source, '', $destination);
                $parts = explode('/', ltrim($relative, '/'), 2);
                $skip[] = $parts[0];
            }

            $sourceDirectory = opendir($source);
            while ($file = readdir($sourceDirectory)) {
                if (in_array($file, $skip) || is_link($source . '/' . $file)) {
                    continue;
                } elseif (is_dir($source . '/' . $file)) {
                    $this->copyAll($source . '/' . $file, $destination . '/' . $file);
                } elseif (is_file($source . '/' . $file)) {
                    $this->fs->copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
            closedir($sourceDirectory);
        }
        else {
            $this->fs->copy($source, $destination);
        }
    }

    /**
     * Create a symbolic link to a file or directory.
     *
     * @param $target
     * @param $link
     *
     * @return string
     *   The final symlink target, which could be a relative path, depending on
     *   $this->relative.
     */
    public function symLink($target, $link)
    {
        if ($target === $link) {
            throw new \InvalidArgumentException("Cannot symlink $link to itself");
        }
        if (file_exists($link)) {
            $this->fs->remove($link);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, $link);
        }
        $this->fs->symlink($target, $link, $this->copyOnWindows);

        return $target;
    }

    /**
     * Symlink or copy all files and folders between two directories.
     *
     * @param string   $source
     * @param string   $destination
     * @param bool     $skipExisting
     * @param bool     $recursive
     * @param string[] $blacklist
     * @param bool     $copy
     *
     * @throws \Exception When a conflict is discovered.
     */
    public function symlinkAll($source, $destination, $skipExisting = true, $recursive = false, $blacklist = [], $copy = false)
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = ['.', '..', '.git'];

        // Go through the blacklist, adding files to $skip.
        foreach ($blacklist as $pattern) {
            $matched = glob($source . '/' . $pattern, GLOB_NOSORT);
            if ($matched) {
                foreach ($matched as $filename) {
                    $relative = str_replace($source . '/', '', $filename);
                    $skip[$relative] = $relative;
                }
            }
        }

        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                $sourceFile = $source . '/' . $file;
                $linkFile = $destination . '/' . $file;

                if ($recursive && !is_link($linkFile) && is_dir($linkFile) && is_dir($sourceFile)) {
                    // Note: the blacklist is not used recursively.
                    $this->symlinkAll($sourceFile, $linkFile, $skipExisting, $recursive, [], $copy);
                    continue;
                }
                elseif (file_exists($linkFile)) {
                    if ($skipExisting) {
                        continue;
                    } else {
                        throw new \Exception('File exists: ' . $linkFile);
                    }
                }
                elseif (is_link($linkFile)) {
                    // This is a broken link. Remove it.
                    $this->remove($linkFile);
                }

                if ($copy) {
                    $this->copyAll($sourceFile, $linkFile);
                }
                else {
                    if ($this->relative) {
                        $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                        chdir($destination);
                    }

                    $this->fs->symlink($sourceFile, $linkFile, $this->copyOnWindows);
                }
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make a absolute path into a relative one.
     *
     * @param string $path1 Absolute path.
     * @param string $path2 Target path.
     *
     * @return string The first path, made relative to the second path.
     */
    public function makePathRelative($path1, $path2)
    {
        if (!is_dir($path2)) {
            $path2 = realpath(dirname($path2));
            if (!$path2) {
                return $path1;
            }
        }
        $result = rtrim($this->fs->makePathRelative($path1, $path2), DIRECTORY_SEPARATOR);

        return $result;
    }

    /**
     * Make a relative path into an absolute one.
     *
     * The realpath() function will only work for existing files, and not for
     * symlinks. This is a more flexible solution.
     *
     * @param string $relativePath
     *
     * @throws \InvalidArgumentException If the parent directory is not found.
     *
     * @return string
     */
    public function makePathAbsolute($relativePath)
    {
        if (file_exists($relativePath) && !is_link($relativePath) && ($realPath = realpath($relativePath))) {
            $absolute = $realPath;
        }
        else {
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
     * Create a gzipped tar archive of a directory's contents.
     *
     * @param string $dir
     * @param string $destination
     */
    public function archiveDir($dir, $destination)
    {
        $tar = $this->getTarExecutable();
        $dir = $this->fixTarPath($dir);
        $destination = $this->fixTarPath($destination);
        $this->shellHelper->execute([$tar, '-czp', '-C' . $dir, '-f' . $destination, '.'], null, true);
    }

    /**
     * Extract a gzipped tar archive into the specified destination directory.
     *
     * @param string $archive
     * @param string $destination
     */
    public function extractArchive($archive, $destination)
    {
        if (!file_exists($archive)) {
            throw new \InvalidArgumentException("Archive not found: $archive");
        }
        if (!is_writable(dirname($destination))) {
            throw new \InvalidArgumentException("Destination not writable: $destination");
        }
        $tar = $this->getTarExecutable();
        if (!file_exists($destination)) {
            mkdir($destination);
        }
        $destination = $this->fixTarPath($destination);
        $archive = $this->fixTarPath($archive);
        $this->shellHelper->execute([$tar, '-xzp', '-C' . $destination, '-f' . $archive], null, true);
    }

    /**
     * Fix a path so that it can be used with tar on Windows.
     *
     * @see http://betterlogic.com/roger/2009/01/tar-woes-with-windows/
     *
     * @param string $path
     *
     * @return string
     */
    protected function fixTarPath($path)
    {
        if ($this->isWindows()) {
            $path = preg_replace_callback(
                '#^([A-Z]):/#i',
                function (array $matches) {
                    return '/' . strtolower($matches[1]) . '/';
                },
                str_replace('\\', '/', $path)
            );
        }

        return $path;
    }

    /**
     * @return string
     */
    protected function getTarExecutable()
    {
        $candidates = ['tar', 'tar.exe', 'bsdtar.exe'];
        foreach ($candidates as $command) {
            if ($this->shellHelper->commandExists($command)) {
                return $command;
            }
        }
        throw new \RuntimeException("Tar command not found");
    }

    /**
     * @return bool
     */
    protected function isWindows()
    {
        return strpos(PHP_OS, 'WIN') !== false;
    }

}
