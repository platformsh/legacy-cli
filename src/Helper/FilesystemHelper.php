<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FilesystemHelper extends Helper {

    protected $relative = false;
    protected $copyIfSymlinkUnavailable = true;

    public function getName()
    {
        return 'fs';
    }

    /**
     * Set whether to use relative links.
     *
     * @param bool $relative
     */
    public function setRelativeLinks($relative)
    {
        // This is not possible on Windows.
        if (strpos(PHP_OS, 'WIN') !== false) {
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
        $fs = new Filesystem();
        try {
            $fs->remove($directory);
        }
        catch (IOException $e) {
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
            }
            elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
                $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
            }
        }

        return $home;
    }

    /**
     * Copy all files and folders between directories.
     *
     * @param string $source
     * @param string $destination
     */
    public function copy($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }
        $fs = new Filesystem();

        $skip = array('.', '..', '.git');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                if (is_dir($source . '/' . $file)) {
                    $this->copy($source . '/' . $file, $destination . '/' . $file);
                } else {
                    $fs->copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Create a symbolic link to a directory.
     *
     * @param string $target The target directory.
     * @param string $link The name of the link.
     */
    public function symlinkDir($target, $link)
    {
        $fs = new Filesystem();
        if (is_link($link)) {
            $fs->remove($link);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, $link);
        }
        $fs->symlink($target, $link, $this->copyIfSymlinkUnavailable);
    }

    /**
     * Symlink all files and folders between two directories.
     *
     * @param string $source
     * @param string $destination
     * @param bool $skipExisting
     * @param string[] $blacklist
     *
     * @throws \Exception
     */
    public function symlinkAll($source, $destination, $skipExisting = true, $blacklist = array())
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array('.', '..');

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

                if ($this->relative) {
                    $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                }

                if (file_exists($linkFile)) {
                    if (is_link($linkFile)) {
                        unlink($linkFile);
                    }
                    elseif ($skipExisting) {
                        continue;
                    }
                    else {
                        throw new \Exception('File exists: ' . $linkFile);
                    }
                }

                if (!function_exists('symlink') && $this->copyIfSymlinkUnavailable) {
                    copy($sourceFile, $linkFile);
                    continue;
                }

                symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make relative path between a symlink and a target.
     *
     * @param string $endPath Path of the file we are linking to.
     * @param string $startPath Path to the symlink that doesn't exist yet.
     *
     * @return string Relative path to the target.
     */
    protected function makePathRelative($endPath, $startPath)
    {
        $startPath = substr($startPath, 0, strrpos($startPath, DIRECTORY_SEPARATOR));
        $fs = new Filesystem();
        $result = rtrim($fs->makePathRelative($endPath, $startPath), DIRECTORY_SEPARATOR);
        return $result;
    }


} 