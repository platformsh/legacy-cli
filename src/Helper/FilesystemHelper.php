<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class FilesystemHelper extends Helper {

    public function getName()
    {
        return 'fs';
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
        if (!is_dir($target)) {
            throw new \InvalidArgumentException("The symlink target must be a directory.");
        }
        if (is_link($link)) {
            // Windows needs rmdir(), other systems need unlink().
            if (strpos(PHP_OS, 'WIN') !== false && is_dir($link)) {
                rmdir($link);
            }
            else {
                unlink($link);
            }
        }
        $fs = new Filesystem();
        $fs->symlink($target, $link, true);
    }

    /**
     * Symlink all files and folders between two directories.
     *
     * @param string $source
     * @param string $destination
     * @param bool $skipExisting
     * @param bool $relative
     * @param array $blacklist
     *
     * @throws \Exception
     */
    public function symlinkAll($source, $destination, $skipExisting = true, $relative = false, $blacklist = array())
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

                if ($relative) {
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

                $fs = new Filesystem();
                $fs->symlink($sourceFile, $linkFile, true);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make relative path between a symlink and a target.
     *
     * @param string $symlink Path to the symlink that doesn't exist yet.
     * @param string $target Path of the file we are linking to.
     *
     * @return string Relative path to the target.
     */
    public function makePathRelative($symlink, $target)
    {
        $target = substr($target, 0, strrpos($target, DIRECTORY_SEPARATOR));
        $fs = new Filesystem();
        $result = rtrim($fs->makePathRelative($symlink, $target), DIRECTORY_SEPARATOR);
        return $result;
    }


} 