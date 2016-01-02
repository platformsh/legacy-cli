<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Helper\GitHelper;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class LocalProject
{

    const ARCHIVE_DIR = '.platform/local/build-archives';
    const BUILD_DIR = '.platform/local/builds';
    const PROJECT_CONFIG = '.platform/local/project.yaml';
    const SHARED_DIR = '.platform/local/shared';
    const WEB_ROOT = 'www';
    const REPOSITORY_DIR = '.'; // for backwards compatibility

    /**
     * Create the default files for a project.
     *
     * @param string $projectRoot
     * @param string $projectId
     * @param string $host
     */
    public function createProjectFiles($projectRoot, $projectId, $host = null)
    {
        mkdir($projectRoot . '/' . self::BUILD_DIR, 0755, true);
        mkdir($projectRoot . '/' . self::SHARED_DIR, 0755, true);

        // Create the .platform-project file.
        $projectConfig = ['id' => $projectId];
        if ($host !== null) {
            $projectConfig['host'] = $host;
        }
        $dumper = new Dumper();
        file_put_contents($projectRoot . '/' . self::PROJECT_CONFIG, $dumper->dump($projectConfig, 2));
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

        if (file_exists($dir . '/' . self::PROJECT_CONFIG)) {
            throw new \RuntimeException("The project is already initialized");
        }

        // Get the project ID from the Git repository.
        if ($projectId === null || $gitUrl === null) {
            $gitUrl = $this->getGitRemoteUrl($dir);
            $projectId = $this->getProjectId($gitUrl);
        }

        // Set up the project.
        $this->createProjectFiles($dir, $projectId);
        $this->ensureGitRemote($dir, $gitUrl);

        return $dir;
    }

    /**
     * @throws \RuntimeException If the URL is not a Platform.sh Git URL.
     *
     * @param string $gitUrl
     */
    protected function getProjectId($gitUrl)
    {
        if (!preg_match('/^([a-z0-9]{12,})@git\.[a-z\-]+\.platform\.sh:\1\.git$/', $gitUrl, $matches)) {
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
        foreach (['platform', 'origin'] as $remote) {
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
            $gitHelper->execute(['remote', 'add', 'platform', $url], $dir, true);
        }
        elseif ($platformUrl != $url) {
            $gitHelper->execute(['remote', 'set-url', 'platform', $url], $dir, true);
        }
        // Add an origin remote too.
        if (!$gitHelper->getConfig("remote.origin.url", $dir)) {
            $gitHelper->execute(['remote', 'add', 'origin', $url]);
        }
    }

    /**
     * Find the root of the current project.
     *
     * The project root contains a .platform/local/project.yaml file. The
     * current directory tree is traversed until the file is found.
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
        file_put_contents($file, $dumper->dump($projectConfig, 2));

        return $projectConfig;
    }
}
