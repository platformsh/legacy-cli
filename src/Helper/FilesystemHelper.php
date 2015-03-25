<?php

namespace Platformsh\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FilesystemHelper extends Helper
{

    protected $relative = false;
    protected $fs;
    protected $copyIfSymlinkUnavailable = true;

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
    }

    /**
     * Set whether to use relative links.
     *
     * @param bool $relative
     */
    public function setRelativeLinks($relative)
    {
        // This is not possible on Windows.
        if ($this->isWindows()) {
            $relative = false;
        }
        $this->relative = $relative;
    }

    /**
     * Delete a directory and all of its files.
     *
     * @param string $directory A path to a directory.
     *
     * @return bool
     */
    public function rmdir($directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Not a directory: $directory");
        }

        return $this->remove($directory);
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
     */
    public function copyAll($source, $destination)
    {
        if (!is_dir($source)) {
            throw new \InvalidArgumentException("Not a directory: $source");
        }
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        $skip = array('.', '..', '.git');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                if (is_dir($source . '/' . $file)) {
                    $this->copyAll($source . '/' . $file, $destination . '/' . $file);
                } else {
                    $this->fs->copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($sourceDirectory);
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
        if (file_exists($link)) {
            $this->fs->remove($link);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, $link);
        }
        $this->fs->symlink($target, $link, $this->copyIfSymlinkUnavailable);

        return $target;
    }

    /**
     * Symlink all files and folders between two directories.
     *
     * @param string   $source
     * @param string   $destination
     * @param bool     $skipExisting
     * @param bool     $recursive
     * @param string[] $blacklist
     *
     * @throws \Exception When a conflict is discovered.
     */
    public function symlinkAll($source, $destination, $skipExisting = true, $recursive = false, $blacklist = array())
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array('.', '..', '.git');

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

                if ($recursive && is_dir($linkFile) && is_dir($sourceFile)) {
                    $this->symlinkAll($sourceFile, $linkFile, $skipExisting, $recursive);
                    continue;
                }
                elseif (file_exists($linkFile)) {
                    if ($skipExisting) {
                        continue;
                    } else {
                        throw new \Exception('File exists: ' . $linkFile);
                    }
                }

                if (!function_exists('symlink') && $this->copyIfSymlinkUnavailable) {
                    copy($sourceFile, $linkFile);
                    continue;
                }

                if ($this->relative) {
                    $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                    chdir($destination);
                }

                symlink($sourceFile, $linkFile);
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
            $path2 = dirname($path2);
        }
        $result = rtrim($this->fs->makePathRelative($path1, $path2), DIRECTORY_SEPARATOR);

        return $result;
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
        $this->shellHelper->execute(array($tar, '-czp', '-C' . $dir, '-f' . $destination, '.'), null, true);
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
        $this->shellHelper->execute(array($tar, '-xzp', '-C' . $destination, '-f' . $archive), null, true);
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
        $candidates = array('tar', 'tar.exe', 'bsdtar.exe');
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
