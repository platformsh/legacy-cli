<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\SiteAlias\DrushPhp;
use Platformsh\Cli\SiteAlias\DrushYaml;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class Drush
{
    /** @var Shell */
    protected $shellHelper;

    /** @var LocalProject */
    protected $localProject;

    /** @var Filesystem */
    protected $fs;

    /** @var Config */
    protected $config;

    /** @var string|null */
    protected $homeDir;

    /** @var array */
    protected $aliases = [];

    /**
     * @param Config|null       $config
     * @param Shell|null        $shellHelper
     * @param LocalProject|null $localProject
     */
    public function __construct(
        Config $config = null,
        Shell $shellHelper = null,
        LocalProject $localProject = null
    ) {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->config = $config ?: new Config();
        $this->localProject = $localProject ?: new LocalProject();
    }

    public function setHomeDir($homeDir)
    {
        $this->homeDir = $homeDir;
    }

    public function getHomeDir()
    {
        return $this->homeDir ?: Filesystem::getHomeDirectory();
    }

    /**
     * Get the installed Drush version.
     *
     * @param bool $reset
     *
     * @return string|false
     *   The Drush version, or false if it cannot be determined.
     *
     * @throws DependencyMissingException
     *   If Drush is not installed.
     */
    protected function getVersion($reset = false)
    {
        static $version;
        if (!$reset && isset($version)) {
            return $version;
        }
        $this->ensureInstalled();
        $command = $this->getDrushExecutable() . ' version';
        exec($command, $output, $returnCode);
        if ($returnCode > 0) {
            return false;
        }

        // Parse the version from the Drush output. It should be a string a bit
        // like " Drush Version   :  8.0.0-beta14 ".
        $lines = array_filter($output);
        if (!preg_match('/[:\s]\s*([0-9]+\.[a-z0-9\-\.]+)\s*$/', reset($lines), $matches)) {
            return false;
        }
        $version = $matches[1];

        return $version;
    }

    /**
     * @throws DependencyMissingException
     */
    public function ensureInstalled()
    {
        static $installed;
        if (empty($installed) && $this->getDrushExecutable() === 'drush'
            && !$this->shellHelper->commandExists('drush')) {
            throw new DependencyMissingException('Drush is not installed');
        }
        $installed = true;
    }

    /**
     * Checks whether Drush supports the --lock argument for the 'make' command.
     *
     * @return bool
     */
    public function supportsMakeLock()
    {
        return version_compare($this->getVersion(), '7.0.0-rc1', '>=');
    }

    /**
     * Checks whether Drush supports YAML-format alias files.
     *
     * @return bool
     */
    public function supportsYamlAliasFiles()
    {
        return $this->getVersion() === false || version_compare($this->getVersion(), '9.0.0-alpha1', '>=');
    }

    /**
     * Checks whether Drush supports PHP-format alias files.
     *
     * @return bool
     */
    public function supportsPhpAliasFiles()
    {
        return $this->getVersion() === false || version_compare($this->getVersion(), '9.0.0', '<');
    }

    /**
     * Execute a Drush command.
     *
     * @param string[] $args
     *   Command arguments (everything after 'drush').
     * @param string   $dir
     *   The working directory.
     * @param bool     $mustRun
     *   Enable exceptions if the command fails.
     * @param bool     $quiet
     *   Suppress command output.
     *
     * @return string|bool
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = true)
    {
        array_unshift($args, $this->getDrushExecutable());

        return $this->shellHelper->execute($args, $dir, $mustRun, $quiet);
    }

    /**
     * Get the full path to the Drush executable.
     *
     * @return string
     *   The absolute path to the executable, or 'drush' if the path is not
     *   known.
     */
    protected function getDrushExecutable()
    {
        if ($this->config->has('local.drush_executable')) {
            return $this->config->get('local.drush_executable');
        }

        // Find a locally installed Drush instance: first check the Composer
        // 'vendor' directory.
        $projectRoot = $this->localProject->getProjectRoot();
        $localDir = $projectRoot ?: getcwd();
        $drushLocal = $localDir . '/vendor/bin/drush';
        if (is_executable($drushLocal)) {
            return $drushLocal;
        }

        // Check the local dependencies directory (created via 'platform
        // build').
        $drushDep = $localDir . '/' . $this->config->get('local.dependencies_dir') . '/php/vendor/bin/drush';
        if (is_executable($drushDep)) {
            return $drushDep;
        }

        // Use the global Drush, if there is one installed.
        if ($this->shellHelper->commandExists('drush')) {
            return $this->shellHelper->resolveCommand('drush');
        }

        // Fall back to the Drush that may be installed within the CLI.
        $drushCli = CLI_ROOT . '/vendor/bin/drush';
        if (is_executable($drushCli)) {
            return $drushCli;
        }

        return 'drush';
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        return (bool) $this->execute(['cache-clear', 'drush']);
    }

    /**
     * Get existing Drush aliases for a group.
     *
     * @param string $groupName
     * @param bool   $reset
     *
     * @return array
     */
    public function getAliases($groupName, $reset = false)
    {
        if (!$reset && !empty($this->aliases[$groupName])) {
            return $this->aliases[$groupName];
        }

        $result = $this->execute(
            [
                '@none',
                'site-alias',
                '--format=json',
                '@' . $groupName,
            ]
        );
        $aliases = [];
        if (is_string($result)) {
            $aliases = (array) json_decode($result, true);
        }
        $this->aliases[$groupName] = $aliases;

        return $aliases;
    }

    /**
     * @return string
     */
    protected function getAutoRemoveKey()
    {
        return preg_replace(
            '/[^a-z-]+/',
            '-',
            str_replace('.', '', strtolower($this->config->get('application.name')))
        ) . '-auto-remove';
    }

    /**
     * Get the alias group for a project.
     *
     * @param Project $project
     * @param string  $projectRoot
     *
     * @return string
     */
    public function getAliasGroup(Project $project, $projectRoot)
    {
        $config = $this->localProject->getProjectConfig($projectRoot);

        return !empty($config['alias-group']) ? $config['alias-group'] : $project['id'];
    }

    /**
     * @param string $newGroup
     * @param string $projectRoot
     */
    public function setAliasGroup($newGroup, $projectRoot)
    {
        $this->localProject->writeCurrentProjectConfig(['alias-group' => $newGroup], $projectRoot, true);
    }

    /**
     * Create Drush aliases for the provided project and environments.
     *
     * @param Project       $project      The project
     * @param string        $projectRoot  The project root
     * @param Environment[] $environments The environments
     * @param string        $original     The original group name
     *
     * @return bool True on success, false on failure.
     */
    public function createAliases(Project $project, $projectRoot, $environments, $original = null)
    {
        $group = $this->getAliasGroup($project, $projectRoot);

        // Gather Drupal applications.
        $apps = array_filter(
            LocalApplication::getApplications($projectRoot, $this->config),
            function (LocalApplication $app) {
                return Drupal::isDrupal($app->getRoot());
            }
        );

        $types = [];
        if ($this->supportsYamlAliasFiles()) {
            $types[] = new DrushYaml($this->config, $this);
        }
        if ($this->supportsPhpAliasFiles()) {
            $types[] = new DrushPhp($this->config, $this);
        }

        $success = true;
        foreach ($types as $type) {
            $success = $success && $type->createAliases($project, $group, $apps, $environments, $original);
        }

        return $success;
    }
}
