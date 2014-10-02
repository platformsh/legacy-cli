<?php

namespace CommerceGuys\Platform\Cli\Toolstack;

use CommerceGuys\Platform\Cli\Command\ProjectBuildCommand;
use Symfony\Component\Console;

abstract class BaseApp
{

    protected $settings = array();
    protected $command;
    protected $appRoot;
    protected $language;
    protected $toolstack;

    function __construct(ProjectBuildCommand $command, $settings = array())
    {
        $this->command = $command;
        $this->settings = $settings;
        $this->projectRoot = $this->settings['projectRoot'];

        $this->appRoot = $this->determineAppRoot($this->settings);
        if (!$this->appRoot) {
            if (!$this->projectRoot) {
                $this->command->output->writeln("<error>You cannot build a project locally from outside of the project's folder structure.</error>");
            }
            else {
                // With no declaration in the settings, we assume the approot
                // to match the repository.
                $this->appRoot = $this->projectRoot . "/repository";
            }
        }
    }

    function determineAppRoot($settings)
    {
        // @todo: Not yet implemented.
        return NULL;
    }

    function prepareBuild()
    {
        $this->buildName = date('Y-m-d--H-i-s') . '--' . $this->settings['environmentId'];
        $this->relBuildDir = 'builds/' . $this->buildName;
        $this->absBuildDir = $this->projectRoot . '/' . $this->relBuildDir;
        $this->absoluteLinks = $this->command->absoluteLinks;
    }

    // @todo: Move this filesystem stuff into a reusable trait somewhere... :(

    /**
     * Copy all files and folders from $source into $destination.
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
     * Symlink all files and folders from $source into $destination.
     */
    protected function symlink($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array('.', '..');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                $sourceFile = $source . '/' . $file;
                $linkFile = $destination . '/' . $file;

                if (!$this->absoluteLinks) {
                    $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                }
                symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    public static function skipLogin()
    {
        return TRUE;
    }

    /**
     * Make relative path between two files.
     *
     * @param string $source Path of the file we are linking to.
     * @param string $destination Path to the symlink.
     * @return string Relative path to the source, or file linking to.
     */
    private function makePathRelative($source, $dest)
    {
        $i = 0;
        while (true) {
            if(substr($source, $i, 1) != substr($dest, $i, 1)) {
                break;
            }
            $i++;
        }
        $distance = substr_count(substr($dest, $i - 1, strlen($dest)), '/') - 1;

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
     */
    protected function rmdir($directoryName)
    {
        if (is_dir($directoryName)) {
            // Recursively empty the directory.
            $directory = opendir($directoryName);
            while ($file = readdir($directory)) {
                if (!in_array($file, array('.', '..'))) {
                    if (is_link($directoryName . '/' . $file)) {
                        unlink($directoryName . '/' . $file);
                    } else if (is_dir($directoryName . '/' . $file)) {
                        $this->rmdir($directoryName . '/' . $file);
                    } else {
                        unlink($directoryName . '/' . $file);
                    }
                }
            }
            closedir($directory);

            // Delete the directory itself.
            rmdir($directoryName);
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
