<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

abstract class ToolstackBase implements ToolstackInterface
{

    protected $settings = array();
    protected $appRoot;
    protected $projectRoot;
    protected $buildName;
    protected $relBuildDir;
    protected $absBuildDir;
    protected $absoluteLinks = false;

    public function prepareBuild($appRoot, $projectRoot, array $settings)
    {
        $this->appRoot = $appRoot;
        $this->projectRoot = $projectRoot;
        $this->settings = $settings;
        $this->buildName = date('Y-m-d--H-i-s') . '--' . $settings['environmentId'];
        $this->relBuildDir = 'builds/' . $this->buildName;
        $this->absBuildDir = $projectRoot . '/' . $this->relBuildDir;
        $this->absoluteLinks = !empty($settings['absoluteLinks']);
        return $this;
    }

    // @todo: Move this filesystem stuff into a reusable trait somewhere... :(

    /**
     * Copy all files and folders between directories.
     *
     * @param string $source
     * @param string $destination
     */
    protected function copy($source, $destination)
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
     * Symlink all files and folders between two directories.
     *
     * @param string $source
     * @param string $destination
     * @param bool $skipExisting
     * @param array $blacklist
     */
    protected function symlink($source, $destination, $skipExisting = true, $blacklist = array())
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

                if (!$this->absoluteLinks) {
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
     */
    private function makePathRelative($source, $destination)
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

    /**
     * Delete a directory and all of its files.
     *
     * @param string $directory
     */
    protected function rmdir($directory)
    {
        if (is_dir($directory)) {
            // Recursively empty the directory.
            $directoryResource = opendir($directory);
            while ($file = readdir($directoryResource)) {
                if (!in_array($file, array('.', '..'))) {
                    if (is_link($directory . '/' . $file)) {
                        unlink($directory . '/' . $file);
                    } else if (is_dir($directory . '/' . $file)) {
                        $this->rmdir($directory . '/' . $file);
                    } else {
                        unlink($directory . '/' . $file);
                    }
                }
            }
            closedir($directoryResource);

            // Delete the directory itself.
            rmdir($directory);
        }
    }

    /**
     * Run a shell command in the current directory, suppressing errors.
     *
     * @param string $cmd The command, suitably escaped.
     * @param string &$error Optionally use this to capture errors.
     *
     * @throws \Exception
     *
     * @return string The command output.
     */
    protected function shellExec($cmd, &$error = '')
    {
      $descriptorSpec = array(
        0 => array('pipe', 'r'), // stdin
        1 => array('pipe', 'w'), // stdout
        2 => array('pipe', 'w'), // stderr
      );
      $process = proc_open($cmd, $descriptorSpec, $pipes);
      if (!$process) {
          throw new \Exception('Failed to execute command');
      }
      $result = stream_get_contents($pipes[1]);
      $error = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);
      return $result;
    }

}
