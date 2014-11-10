<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;

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
        // Recursively empty the directory.
        $directoryResource = opendir($directory);
        while ($file = readdir($directoryResource)) {
            if (!in_array($file, array('.', '..'))) {
                if (is_link($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                } else if (is_dir($directory . '/' . $file)) {
                    $success = $this->rmdir($directory . '/' . $file);
                    if (!$success) {
                        return false;
                    }
                } else {
                    unlink($directory . '/' . $file);
                }
            }
        }
        closedir($directoryResource);

        // Delete the directory itself.
        return rmdir($directory);
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

        $skip = array('.', '..', '.git');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                if (is_dir($source . '/' . $file)) {
                    $this->copy($source . '/' . $file, $destination . '/' . $file);
                } else {
                    copy($source . '/' . $file, $destination . '/' . $file);
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
     *
     * @return bool TRUE on success, FALSE on failure.
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
        return symlink($target, $link);
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

                symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make relative path between two files.
     *
     * @param string $source Path of the file we are linking to.
     * @param string $destination Path to the symlink.
     * @return string Relative path to the source, or file linking to.
     *
     * @todo make this work for more cases, it is hard to test
     */
    public function makePathRelative($source, $destination)
    {
        $i = 0;
        while (true) {
            if(substr($source, $i, 1) != substr($destination, $i, 1)) {
                break;
            }
            $i++;
        }
        $distance = substr_count(substr($destination, $i - 1, strlen($destination)), '/') - 1;

        $path = '';
        while ($distance) {
            $path .= '../';
            $distance--;
        }
        $path .= substr($source, $i, strlen($source));

        return $path;
    }


} 