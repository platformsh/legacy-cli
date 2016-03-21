<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Helper\GitHelper;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class LocalProject
{

    /**
     * @param string $gitUrl
     *
     * @return array|false
     *   An array containing 'id' and 'host', or false on failure.
     */
    protected function getProjectId($gitUrl)
    {
        if (!preg_match('/^([a-z0-9]{12,})@git\.([a-z\-]+\.' . preg_quote(CLI_PROJECT_GIT_DOMAIN) . '):\1\.git$/', $gitUrl, $matches)) {
            return false;
        }

        return ['id' => $matches[1], 'host' => $matches[2]];
    }

    /**
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If no remote can be found.
     *
     * @return string|false
     *   The Git remote URL.
     */
    protected function getGitRemoteUrl($dir)
    {
        $gitHelper = new GitHelper();
        $gitHelper->ensureInstalled();
        foreach ([CLI_GIT_REMOTE_NAME, 'origin'] as $remote) {
            if ($url = $gitHelper->getConfig("remote.$remote.url", $dir)) {
                return $url;
            }
        }

        return false;
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
        $platformUrl = $gitHelper->getConfig("remote." . CLI_GIT_REMOTE_NAME . ".url", $dir);
        if (!$platformUrl) {
            $gitHelper->execute(['remote', 'add', CLI_GIT_REMOTE_NAME, $url], $dir, true);
        }
        elseif ($platformUrl != $url) {
            $gitHelper->execute(['remote', 'set-url', CLI_GIT_REMOTE_NAME, $url], $dir, true);
        }
        // Add an origin remote too.
        if (!$gitHelper->getConfig("remote.origin.url", $dir)) {
            $gitHelper->execute(['remote', 'add', 'origin', $url]);
        }
    }

    /**
     * Find the highest level directory that contains a file.
     *
     * @param string $file
     *   The filename to look for.
     * @param callable $callback
     *   A callback to validate the directory when found. Accepts one argument
     *   (the directory path). Return true to use the directory, or false to
     *   continue traversing upwards.
     *
     * @return string|false
     *   The path to the directory, or false if the file is not found.
     */
    protected static function findTopDirectoryContaining($file, callable $callback = null)
    {
        static $roots = [];
        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }
        if (isset($roots[$cwd][$file])) {
            return $roots[$cwd][$file];
        }

        $roots[$cwd][$file] = false;
        $root = &$roots[$cwd][$file];

        $currentDir = $cwd;
        while (!$root) {
            if (file_exists($currentDir . '/' . $file)) {
                if ($callback === null || $callback($currentDir)) {
                    $root = $currentDir;
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

        return $root;
    }

    /**
     * Find the legacy root of the current project, from CLI versions <3.
     *
     * @return string|false
     */
    public static function getLegacyProjectRoot()
    {
        return self::findTopDirectoryContaining(CLI_LOCAL_PROJECT_CONFIG_LEGACY);
    }

    /**
     * Find the root of the current project.
     *
     * @return string|false
     */
    public function getProjectRoot()
    {
        // Backwards compatibility - if in an old-style project root, change
        // directory to the repository.
        if (file_exists(CLI_LOCAL_PROJECT_CONFIG_LEGACY) && is_dir('repository')) {
            $cwd = getcwd();
            chdir('repository');
        }

        // The project root is a Git repository, which contains a PROJECT_CONFIG
        // configuration file, and/or contains a CLI_PROJECT_GIT_DOMAIN Git
        // remote.
        $dir = $this->findTopDirectoryContaining('.git', function ($dir) {
            if (file_exists($dir . '/' . CLI_LOCAL_PROJECT_CONFIG)) {
                return true;
            }
            $gitUrl = $this->getGitRemoteUrl($dir);
            if (!$gitUrl || !($projectId = $this->getProjectId($gitUrl))) {
                return false;
            }
            // Backwards compatibility: copy old project config to new
            // location.
            if (file_exists($dir . '/../' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
                $this->ensureLocalDir($dir);
                copy($dir . '/../' . CLI_LOCAL_PROJECT_CONFIG_LEGACY, $dir . '/' . CLI_LOCAL_PROJECT_CONFIG);
            }
            $this->writeCurrentProjectConfig($projectId, $dir);
            return true;
        });

        if (isset($cwd)) {
            chdir($cwd);
        }

        return $dir;
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
        if ($projectRoot && file_exists($projectRoot . '/' . CLI_LOCAL_PROJECT_CONFIG)) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . CLI_LOCAL_PROJECT_CONFIG));
        }
        elseif ($projectRoot && is_dir($projectRoot . '/.git')) {
            $gitUrl = $this->getGitRemoteUrl($projectRoot);
            if ($gitUrl && ($projectId = $this->getProjectId($gitUrl))) {
                $projectConfig = $projectId;
            }
        }

        return $projectConfig;
    }

    /**
     * Write configuration for a project.
     *
     * @param array $config The configuration.
     * @param string $projectRoot
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    public function writeCurrentProjectConfig(array $config, $projectRoot = null)
    {
        $projectRoot = $projectRoot ?: self::getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $this->ensureLocalDir($projectRoot);
        $file = $projectRoot . '/' . CLI_LOCAL_PROJECT_CONFIG;
        $projectConfig = self::getProjectConfig($projectRoot) ?: [];
        $projectConfig = array_merge($projectConfig, $config);
        $dumper = new Dumper();
        if (file_put_contents($file, $dumper->dump($projectConfig, 10)) === false) {
            throw new \Exception('Failed to write project config file: ' . $file);
        }

        return $projectConfig;
    }

    /**
     * @param string $projectRoot
     */
    public function ensureLocalDir($projectRoot)
    {
        $dir = $projectRoot . '/' . CLI_LOCAL_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->writeGitExclude($projectRoot);
        }
        if (!file_exists($dir . '/README.txt')) {
            $cliName = CLI_NAME;
            $localDir = CLI_LOCAL_DIR;
            file_put_contents($dir . '/README.txt', <<<EOF
{$localDir}
===============

This directory is where the {$cliName} stores configuration files, builds, and
other data to help work with your project locally.

It is not used on remote environments at all - the directory is excluded from
your Git repository (via .git/info/exclude).

EOF
            );
        }
    }

    /**
     * Write to the Git exclude file.
     *
     * @param string $dir
     */
    public function writeGitExclude($dir)
    {
        $filesToExclude = ['/' . CLI_LOCAL_DIR, '/' . CLI_LOCAL_WEB_ROOT];
        $excludeFilename = $dir . '/.git/info/exclude';
        $existing = '';
        if (file_exists($excludeFilename)) {
            $existing = file_get_contents($excludeFilename);
            if (strpos($existing, CLI_NAME) !== false) {
                return;
            }
        }
        $content = "# Automatically added by the " . CLI_NAME . "\n"
            . implode("\n", $filesToExclude)
            . "\n";
        if (!empty($existing)) {
            $content = $existing . "\n" . $content;
        }
        if (file_put_contents($excludeFilename, $content) === false) {
            throw new \RuntimeException("Failed to write to Git exclude file: " . $excludeFilename);
        }
    }
}
