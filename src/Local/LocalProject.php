<?php

namespace CommerceGuys\Platform\Cli\Local;

use CommerceGuys\Platform\Cli\Helper\GitHelper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class LocalProject
{

    const ARCHIVE_DIR = '.build-archives';
    const BUILD_DIR = 'builds';
    const PROJECT_CONFIG = '.platform-project';
    const REPOSITORY_DIR = 'repository';
    const SHARED_DIR = 'shared';
    const WEB_ROOT = 'www';

    /**
     * Create the default files for a project.
     *
     * @param string $projectRoot
     * @param string $projectId
     */
    public function createProjectFiles($projectRoot, $projectId)
    {
        mkdir($projectRoot . '/' . self::BUILD_DIR);
        mkdir($projectRoot . '/' . self::SHARED_DIR);

        // Create the .platform-project file.
        $projectConfig = array(
          'id' => $projectId,
        );
        $dumper = new Dumper();
        file_put_contents($projectRoot . '/' . self::PROJECT_CONFIG, $dumper->dump($projectConfig));
    }

    /**
     * Initialize a project in a directory.
     *
     * @param string $dir
     *
     * @throws \RuntimeException
     *
     * @return string The absolute path to the project.
     */
    public function initialize($dir) {
        $realPath = realpath($dir);
        if (!$realPath) {
            throw new \RuntimeException("Directory not readable: $dir");
        }

        $dir = $realPath;

        if (file_exists($dir . '/../' . self::PROJECT_CONFIG)) {
            throw new \RuntimeException("The project is already initialized");
        }

        // Get the project ID from the Git repository.
        $projectId = $this->getProjectIdFromGit($dir);

        // Move the directory into a 'repository' subdirectory.
        $backupDir = $this->getBackupDir($dir);

        $fs = new Filesystem();
        $fs->rename($dir, $backupDir);
        $fs->mkdir($dir, 0755);
        $fs->rename($backupDir, $dir . '/' . LocalProject::REPOSITORY_DIR);

        $this->createProjectFiles($dir, $projectId);

        return $dir;
    }

    /**
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If the directory is not a clone of a Platform.sh Git repository.
     *
     * @return string|false
     *   The project ID, or false if it cannot be determined.
     */
    protected function getProjectIdFromGit($dir)
    {
        if (!file_exists("$dir/.git")) {
            throw new \RuntimeException('The directory is not a Git repository');
        }
        $gitHelper = new GitHelper();
        $gitHelper->ensureInstalled();
        $originUrl = $gitHelper->getConfig("remote.origin.url", $dir);
        if (!$originUrl) {
            throw new \RuntimeException("Git remote 'origin' not found");
        }
        if (!preg_match('/^([a-z][a-z0-9]{12})@git\.[a-z\-]+\.platform\.sh:\1\.git$/', $originUrl, $matches)) {
            throw new \RuntimeException("The Git remote 'origin' is not a Platform.sh URL");
        }
        return $matches[1];
    }

    /**
     * Get a backup name for a directory.
     *
     * @param $dir
     * @param int $inc
     *
     * @return string
     */
    protected function getBackupDir($dir, $inc = 0)
    {
        $backupDir = $dir . '-backup';
        $backupDir .= $inc ?: '';
        if (file_exists($backupDir)) {
            return $this->getBackupDir($dir, ++$inc);
        }
        return $backupDir;
    }

    /**
     * Find the root of the current project.
     *
     * The project root contains a .platform-project YAML file. The current
     * directory tree is traversed until the file is found.
     *
     * @param bool $reset Reset the static cache for this check.
     *
     * @return string|false
     */
    public static function getProjectRoot($reset = false)
    {
        static $projectRoot;
        if ($projectRoot !== null && !$reset) {
            return $projectRoot;
        }

        $currentDir = getcwd();
        $projectRoot = false;
        while (!$projectRoot) {
            if (file_exists($currentDir . '/' . self::PROJECT_CONFIG)) {
                $projectRoot = $currentDir;
                break;
            }

            // The file was not found, go one directory up.
            $levelUp = dirname($currentDir);
            if ($levelUp === $currentDir || $levelUp === '.') {
                break;
            }
            $currentDir = $levelUp;
        }

        return $projectRoot;
    }

    /**
     * Get the configuration for the current project.
     *
     * @return array|null
     *   The current project's configuration.
     */
    public static function getCurrentProjectConfig() {
        $projectConfig = null;
        $projectRoot = self::getProjectRoot();
        if ($projectRoot) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . self::PROJECT_CONFIG));
        }
        return $projectConfig;
    }

    /**
     * Add a configuration value to a project.
     *
     * @param string $key The configuration key
     * @param mixed $value The configuration value
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    public static function writeCurrentProjectConfig($key, $value) {
        $projectConfig = self::getCurrentProjectConfig();
        if (!$projectConfig) {
            throw new \Exception('Current project configuration not found');
        }
        $projectRoot = self::getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $file = $projectRoot . '/' . self::PROJECT_CONFIG;
        if (!is_writable($file)) {
            throw new \Exception('Project config file not writable');
        }
        $dumper = new Dumper();
        $projectConfig[$key] = $value;
        file_put_contents($file, $dumper->dump($projectConfig));
        return $projectConfig;
    }

}
