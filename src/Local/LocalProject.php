<?php

namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Io;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class LocalProject
{
    protected Config $config;
    protected Filesystem $fs;
    protected Git $git;
    protected Io $io;

    protected static array $projectConfigs = [];

    public function __construct(?Config $config = null, ?Git $git = null, ?Io $io = null)
    {
        $this->config = $config ?: new Config();
        $this->git = $git ?: new Git();
        $this->io = $io ?: new Io(new ConsoleOutput());
        $this->fs = new Filesystem();
    }

    /**
     * Read a config file for a project.
     *
     * @param string $dir        The project root.
     * @param string $configFile A config file such as 'services.yaml'.
     *
     * @return array|null
     */
    public function readProjectConfigFile(string $dir, string $configFile): ?array
    {
        $result = null;
        $filename = $dir . '/' . $this->config->getStr('service.project_config_dir') . '/' . $configFile;
        if (file_exists($filename)) {
            $parser = new Parser();
            $result = $parser->parse(file_get_contents($filename));
        }

        return $result;
    }

    /**
     * @return array|false
     *   An array containing 'id' and 'host', or false on failure.
     */
    public function parseGitUrl(string $gitUrl): false|array
    {
        $gitDomain = $this->config->get('detection.git_domain');
        $pattern = '/^([a-z0-9]{12,})@git\.(([a-z0-9\-]+\.)?' . preg_quote((string) $gitDomain) . '):\1\.git$/';
        if (!preg_match($pattern, $gitUrl, $matches)) {
            return false;
        }

        return ['id' => $matches[1], 'host' => $matches[2]];
    }

    /**
     * Finds the git remote URL for a repository.
     *
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If no remote can be found.
     *
     * @return string|false
     *   The Git remote URL.
     */
    protected function getGitRemoteUrl(string $dir): string|false
    {
        $this->git->ensureInstalled();
        foreach ([$this->config->get('detection.git_remote_name'), 'origin'] as $remote) {
            if ($url = $this->git->getConfig("remote.$remote.url", $dir)) {
                return $url;
            }
        }

        return false;
    }

    /**
     * Ensure there is an appropriate Git remote in the repository.
     *
     * @param string $dir
     *   The repository directory.
     * @param string $url
     *   The Git URL.
     */
    public function ensureGitRemote(string $dir, string $url): void
    {
        if (!file_exists($dir . '/.git')) {
            throw new \InvalidArgumentException('The directory is not a Git repository');
        }
        $this->git->ensureInstalled();
        $currentUrl = $this->git->getConfig(
            sprintf('remote.%s.url', $this->config->get('detection.git_remote_name')),
            $dir
        );
        if (!$currentUrl) {
            $this->git->execute(
                ['remote', 'add', $this->config->get('detection.git_remote_name'), $url],
                $dir,
                true
            );
        } elseif ($currentUrl !== $url) {
            $this->git->execute([
                'remote',
                'set-url',
                $this->config->get('detection.git_remote_name'),
                $url
            ], $dir, true);
        }
    }

    /**
     * Find the highest level directory that contains a file.
     *
     * @param string $file
     *   The filename to look for.
     * @param ?string $startDir
     *   An absolute path to a directory to start in.
     *   Defaults to the current directory.
     * @param ?callable $callback
     *   A callback to validate the directory when found. Accepts one argument
     *   (the directory path). Return true to use the directory, or false to
     *   continue traversing upwards.
     *
     * @return string|false
     *   The path to the directory, or false if the file is not found. Where
     *   possible this will be an absolute, real path.
     */
    protected static function findTopDirectoryContaining(string $file, ?string $startDir = null, ?callable $callback = null): string|false
    {
        static $roots = [];
        $startDir = $startDir ?: getcwd();
        if ($startDir === false) {
            return false;
        }
        if (isset($roots[$startDir][$file])) {
            return $roots[$startDir][$file];
        }

        $roots[$startDir][$file] = false;
        $root = &$roots[$startDir][$file];

        $currentDir = $startDir;
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

        if ($root === false) {
            return false;
        }

        return realpath($root) ?: $root;
    }

    /**
     * Initialize a directory as a project root.
     *
     * @param string $directory
     *   The Git repository that should be initialized.
     * @param Project $project
     *   The project.
     */
    public function mapDirectory(string $directory, Project $project): void
    {
        if (!file_exists($directory . '/.git')) {
            throw new \InvalidArgumentException('Not a Git repository: ' . $directory);
        }
        $projectConfig = [
            'id' => $project->id,
        ];
        if ($host = parse_url($project->getUri(), PHP_URL_HOST)) {
            $projectConfig['host'] = $host;
        }
        $this->writeCurrentProjectConfig($projectConfig, $directory, true);
        $this->ensureGitRemote($directory, $project->getGitUrl());
    }

    /**
     * Finds the legacy root of the current project, from CLI versions <3.
     */
    public function getLegacyProjectRoot(?string $startDir = null): string|false
    {
        if (!$this->config->has('local.project_config_legacy')) {
            return false;
        }
        return $this->findTopDirectoryContaining($this->config->get('local.project_config_legacy'), $startDir);
    }

    /**
     * Finds the root of the current project.
     */
    public function getProjectRoot(?string $startDir = null): string|false
    {
        $startDir = $startDir ?: getcwd();

        static $cache = [];
        if (isset($cache[$startDir])) {
            return $cache[$startDir];
        }

        $this->io->debug('Finding the project root');

        // Backwards compatibility - if in an old-style project root, change
        // directory to the repository.
        if (is_dir($startDir . '/repository') && $this->config->has('local.project_config_legacy') && file_exists($startDir . '/' . $this->config->get('local.project_config_legacy'))) {
            $startDir = $startDir . '/repository';
        }

        // The project root is a Git repository, which contains a project
        // configuration file, and/or contains a Git remote with the appropriate
        // domain.
        $result = $this->findTopDirectoryContaining('.git', $startDir, function ($dir): bool {
            $config = $this->getProjectConfig($dir);

            return !empty($config);
        });
        $this->io->debug(
            $result ? 'Project root found: ' . $result : 'Project root not found'
        );

        return $cache[$startDir] = $result;
    }

    /**
     * Gets the configuration for the current project.
     *
     * @return array|null
     *   The current project's configuration.
     *
     * @throws \Exception
     */
    public function getProjectConfig(?string $projectRoot = null): array|null
    {
        $projectRoot = $projectRoot ?: $this->getProjectRoot();
        if (isset(self::$projectConfigs[$projectRoot])) {
            return self::$projectConfigs[$projectRoot];
        }
        $projectConfig = null;
        $configFilename = $this->config->get('local.project_config');
        if ($projectRoot && file_exists($projectRoot . '/' . $configFilename)) {
            $yaml = new Parser();
            $projectConfig = $yaml->parse(file_get_contents($projectRoot . '/' . $configFilename));
            self::$projectConfigs[$projectRoot] = $projectConfig;
        } elseif ($projectRoot && is_dir($projectRoot . '/.git')) {
            $gitUrl = $this->getGitRemoteUrl($projectRoot);
            if ($gitUrl && ($projectConfig = $this->parseGitUrl($gitUrl))) {
                $this->writeCurrentProjectConfig($projectConfig, $projectRoot);
            }
        }

        return $projectConfig;
    }

    /**
     * Writes configuration for a project.
     *
     * Configuration is stored as YAML, in the location configured by
     * 'local.project_config'.
     *
     * @param array $config
     *   The configuration.
     * @param ?string $projectRoot
     *   The project root.
     * @param bool $merge
     *   Whether to merge with existing configuration.
     *
     * @throws \Exception On failure
     *
     * @return array
     *   The updated project configuration.
     */
    public function writeCurrentProjectConfig(array $config, ?string $projectRoot = null, bool $merge = false): array
    {
        $projectRoot = $projectRoot ?: $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('Project root not found');
        }
        $this->ensureLocalDir($projectRoot);
        $file = $projectRoot . '/' . $this->config->get('local.project_config');
        if ($merge) {
            $projectConfig = $this->getProjectConfig($projectRoot) ?: [];
            $config = array_merge($projectConfig, $config);
        }
        $yaml = (new Dumper())->dump($config, 10);
        $this->fs->dumpFile($file, $yaml);

        self::$projectConfigs[$projectRoot] = $config;

        return $config;
    }

    /**
     * @param string $projectRoot
     */
    public function ensureLocalDir(string $projectRoot): void
    {
        $localDirRelative = $this->config->get('local.local_dir');
        $dir = $projectRoot . '/' . $localDirRelative;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->writeGitExclude($projectRoot);
        if (!file_exists($dir . '/.gitignore')) {
            file_put_contents($dir . '/.gitignore', '/' . PHP_EOL);
        }
        if (!file_exists($dir . '/README.txt')) {
            $cliName = $this->config->getStr('application.name');
            file_put_contents($dir . '/README.txt', <<<EOF
{$localDirRelative}
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
    public function writeGitExclude(string $dir): void
    {
        $filesToExclude = ['/' . $this->config->get('local.local_dir'), '/' . $this->config->getWithDefault('local.web_root', '_www')];
        $excludeFilename = $dir . '/.git/info/exclude';
        $existing = '';

        // Skip writing anything if the contents already include the
        // application.name.
        if (file_exists($excludeFilename)) {
            $existing = file_get_contents($excludeFilename);
            if (str_contains($existing, $this->config->getStr('application.name'))) {
                // Backwards compatibility between versions 3.0.0 and 3.0.2.
                $newRoot = "\n" . '/' . $this->config->getStr('application.name') . "\n";
                $oldRoot = "\n" . '/.www' . "\n";
                if (str_contains($existing, $oldRoot) && !str_contains($existing, $newRoot)) {
                    $this->fs->dumpFile($excludeFilename, str_replace($oldRoot, $newRoot, $existing));
                }
                if (is_link($dir . '/.www')) {
                    unlink($dir . '/.www');
                }
                // End backwards compatibility block.

                return;
            }
        }

        $content = "# Automatically added by the " . $this->config->getStr('application.name') . "\n"
            . implode("\n", $filesToExclude)
            . "\n";
        if (!empty($existing)) {
            $content = $existing . "\n" . $content;
        }
        $this->fs->dumpFile($excludeFilename, $content);
    }
}
