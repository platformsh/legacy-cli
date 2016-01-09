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

    private static $projectConfigs = [];

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
        $this->writeCurrentProjectConfig('id', $projectId, $dir);
        $this->ensureGitRemote($dir, $gitUrl);

        return $dir;
    }

    /**
     * @param string $gitUrl
     *
     * @return string|false
     */
    protected function getProjectId($gitUrl)
    {
        if (!preg_match('/^([a-z0-9]{12,})@git\.[a-z\-]+\.platform\.sh:\1\.git$/', $gitUrl, $matches)) {
            return false;
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
     * The project root is a Git repository with a Platform.sh remote. The
     * current directory tree is traversed until the Git root is found.
     *
     * @return string|false
     */
    public function getProjectRoot()
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
            if (file_exists($currentDir . '/.git')) {
                // It's a Platform.sh project if the project configuration file
                // exists, or if it contains a Platform.sh Git remote.
                if (file_exists($currentDir . '/' . self::PROJECT_CONFIG)) {
                    $projectRoot = $currentDir;
                    break;
                }
                $projectId = $this->getProjectId($this->getGitRemoteUrl($currentDir));
                if ($projectId) {
                    $projectRoot = $currentDir;
                    // Backwards compatibility: copy old project config to new
                    // location.
                    if (file_exists($projectRoot . '/../.platform-project')) {
                        copy($projectRoot . '/../.platform-project', $projectRoot . '/' . self::PROJECT_CONFIG);
                    }
                    else {
                        $this->writeCurrentProjectConfig('id', $projectId, $projectRoot);
                    }
                    break;
                }
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
    public function getProjectConfig($projectRoot = null)
    {
        $projectRoot = $projectRoot ?: self::getProjectRoot();
        $projectConfig = null;
        if ($projectRoot && file_exists($projectRoot . '/' . self::PROJECT_CONFIG)) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . self::PROJECT_CONFIG));
        }
        elseif ($projectRoot && is_dir($projectRoot . '/.git')) {
            $projectId = $this->getProjectId($this->getGitRemoteUrl($projectRoot));
            if ($projectId) {
                $projectConfig = ['id' => $projectId];
            }
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
    public function writeCurrentProjectConfig($key, $value, $projectRoot = null)
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
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $dumper = new Dumper();
        $projectConfig[$key] = $value;
        if (file_put_contents($file, $dumper->dump($projectConfig, 2)) === false) {
            throw new \Exception('Failed to write project config file: ' . $file);
        }

        return $projectConfig;
    }
}
