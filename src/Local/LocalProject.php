<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Helper\GitHelper;
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
     * @param string $host
     */
    public function createProjectFiles($projectRoot, $projectId, $host = null)
    {
        mkdir($projectRoot . '/' . self::BUILD_DIR);
        mkdir($projectRoot . '/' . self::SHARED_DIR);

        // Create the .platform-project file.
        $projectConfig = ['id' => $projectId];
        if ($host !== null) {
            $projectConfig['host'] = $host;
        }
        $dumper = new Dumper();
        file_put_contents($projectRoot . '/' . self::PROJECT_CONFIG, $dumper->dump($projectConfig));
    }

    /**
     * Initialize a project in a directory.
     *
     * @param string $dir
     *   The existing repository directory.
     * @param string $projectId
     *   The project ID (optional). If no project is specified, the project ID
     *   and git URL will be automatically detected from the repository.
     * @param string $gitUrl
     *   The project's git URL (optional).
     *
     * @throws \RuntimeException
     *
     * @return string The absolute path to the project.
     */
    public function initialize($dir, $projectId = null, $gitUrl = null)
    {
        $realPath = realpath($dir);
        if (!$realPath) {
            throw new \RuntimeException("Directory not readable: $dir");
        }

        $dir = $realPath;
        if (!file_exists("$dir/.git")) {
            throw new \RuntimeException('The directory is not a Git repository');
        }

        if (file_exists($dir . '/../' . self::PROJECT_CONFIG)) {
            throw new \RuntimeException("The project is already initialized");
        }

        // Get the project ID from the Git repository.
        if ($projectId === null || $gitUrl === null) {
            $gitUrl = $this->getGitRemoteUrl($dir);
            $projectId = $this->getProjectId($gitUrl);
        }

        // Move the directory into a 'repository' subdirectory.
        $backupDir = $this->getBackupDir($dir);
        $repositoryDir = $dir . '/' . LocalProject::REPOSITORY_DIR;
        $fs = new Filesystem();
        $fs->rename($dir, $backupDir);
        $fs->mkdir($dir, 0755);
        $fs->rename($backupDir, $repositoryDir);

        // Set up the project.
        $this->createProjectFiles($dir, $projectId);
        $this->ensureGitRemote($repositoryDir, $gitUrl);

        return $dir;
    }

    /**
     * @throws \RuntimeException If the URL is not a Platform.sh Git URL.
     *
     * @param string $gitUrl
     */
    protected function getProjectId($gitUrl)
    {
        if (!preg_match('/^([a-z][a-z0-9]{12})@git\.[a-z\-]+\.platform\.sh:\1\.git$/', $gitUrl, $matches)) {
            throw new \RuntimeException("Not a Platform.sh Git URL: $gitUrl");
        }

        return $matches[1];
    }

    /**
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If no remote can be found.
     *
     * @return string
     *   The Git remote URL.
     */
    protected function getGitRemoteUrl($dir)
    {
        $gitHelper = new GitHelper();
        $gitHelper->ensureInstalled();
        foreach (['origin', 'platform'] as $remote) {
            if ($url = $gitHelper->getConfig("remote.$remote.url", $dir)) {
                return $url;
            }
        }
        throw new \RuntimeException("Git remote URL not found");
    }

    /**
     * Ensure there are appropriate Git remotes in the repository.
     *
     * @param string $dir
     * @param string $url
     */
    public function ensureGitRemote($dir, $url)
    {
        if (!file_exists("$dir/.git")) {
            throw new \InvalidArgumentException('The directory is not a Git repository');
        }
        $gitHelper = new GitHelper();
        $gitHelper->ensureInstalled();
        $gitHelper->setDefaultRepositoryDir($dir);
        $platformUrl = $gitHelper->getConfig("remote.platform.url", $dir);
        if (!$platformUrl) {
            $gitHelper->execute(array('remote', 'add', 'platform', $url), $dir, true);
        }
        elseif ($platformUrl != $url) {
            $gitHelper->execute(array('remote', 'set-url', 'platform', $url), $dir, true);
        }
        // Add an origin remote too.
        if (!$gitHelper->getConfig("remote.origin.url", $dir)) {
            $gitHelper->execute(array('remote', 'add', 'origin', $url));
        }
    }

    /**
     * Get a backup name for a directory.
     *
     * @param     $dir
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
     * @return string|false
     */
    public static function getProjectRoot()
    {
        // Statically cache the result, unless the CWD changes.
        static $projectRoot, $lastDir;
        $cwd = getcwd();
        if ($projectRoot !== null && $lastDir === $cwd) {
            return $projectRoot;
        }

        $lastDir = $cwd;
        $projectRoot = false;

        // It's possible that getcwd() can fail.
        if ($cwd === false) {
            return false;
        }

        $currentDir = $cwd;
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
     * @param string $projectRoot
     *
     * @return array|null
     *   The current project's configuration.
     */
    public static function getProjectConfig($projectRoot = null)
    {
        $projectConfig = null;
        $projectRoot = $projectRoot ?: self::getProjectRoot();
        if ($projectRoot && file_exists($projectRoot . '/' . self::PROJECT_CONFIG)) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . self::PROJECT_CONFIG));
        }

        return $projectConfig;
    }

    /**
     * Add a configuration value to a project.
     *
     * @param string $key   The configuration key
     * @param mixed  $value The configuration value
     * @param string $projectRoot
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    public static function writeCurrentProjectConfig($key, $value, $projectRoot = null)
    {
        $projectRoot = $projectRoot ?: self::getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $projectConfig = self::getProjectConfig($projectRoot);
        if (!$projectConfig) {
            throw new \Exception('Current project configuration not found');
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
