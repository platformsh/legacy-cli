<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\SiteAlias\DrushPhp;
use Platformsh\Cli\SiteAlias\DrushYaml;
use Platformsh\Cli\SiteAlias\SiteAliasTypeInterface;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class Drush
{
    protected Api $api;
    protected Shell $shellHelper;
    protected LocalProject $localProject;
    protected Config $config;
    protected ApplicationFinder $applicationFinder;

    protected ?string $homeDir = null;
    /** @var array<string, array<string, mixed>> */
    protected array $aliases = [];
    protected string|false|null $version;
    protected ?string $executable = null;

    /** @var string[] */
    protected array $cachedAppRoots = [];

    /**
     * @param Config|null $config
     * @param Shell|null $shellHelper
     * @param LocalProject|null $localProject
     * @param Api|null $api
     * @param ApplicationFinder|null $applicationFinder
     */
    public function __construct(
        ?Config $config = null,
        ?Shell $shellHelper = null,
        ?LocalProject $localProject = null,
        ?Api $api = null,
        ?ApplicationFinder $applicationFinder = null,
    ) {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->config = $config ?: new Config();
        $this->localProject = $localProject ?: new LocalProject();
        $this->api = $api ?: new Api($this->config);
        $this->applicationFinder = $applicationFinder ?: new ApplicationFinder($this->config);
    }

    public function setHomeDir(string $homeDir): void
    {
        $this->homeDir = $homeDir;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir ?: $this->config->getHomeDirectory();
    }

    public function setCachedAppRoot(string $sshUrl, string $enterpriseAppRoot): void
    {
        $this->cachedAppRoots[$sshUrl] = $enterpriseAppRoot;
    }

    public function getCachedAppRoot(string $sshUrl): string|false
    {
        return $this->cachedAppRoots[$sshUrl] ?? false;
    }

    /**
     * Finds the global Drush configuration directory.
     */
    public function getDrushDir(): string
    {
        return $this->getHomeDir() . '/.drush';
    }

    /**
     * Find the directory where global Drush site aliases should be stored.
     *
     * @return string
     */
    public function getSiteAliasDir(): string
    {
        $aliasDir = $this->getDrushDir() . '/site-aliases';
        if (!file_exists($aliasDir) && $this->getLegacyAliasFiles()) {
            $aliasDir = $this->getDrushDir();
        }

        return $aliasDir;
    }

    /**
     * Get a list of legacy alias files.
     *
     * @return string[]
     */
    public function getLegacyAliasFiles(): array
    {
        return glob($this->getDrushDir() . '/*.alias*.*', GLOB_NOSORT) ?: [];
    }

    /**
     * Get the installed Drush version.
     *
     * @param bool $reset
     *
     * @return string|false
     *   The Drush version, or false if it cannot be determined.
     */
    public function getVersion(bool $reset = false): string|false
    {
        if ($reset || !isset($this->version)) {
            $this->version = $this->shellHelper->execute(
                [$this->getDrushExecutable(), 'version', '--format=string'],
            );
        }


        return $this->version;
    }

    /**
     * @throws DependencyMissingException
     */
    public function ensureInstalled(): void
    {
        if ($this->getVersion() === false) {
            throw new DependencyMissingException('Drush is not installed');
        }
    }

    /**
     * Checks whether Drush supports the --lock argument for the 'make' command.
     *
     * @return bool
     */
    public function supportsMakeLock(): bool
    {
        return version_compare((string) $this->getVersion(), '7.0.0-rc1', '>=');
    }

    /**
     * Execute a Drush command.
     *
     * @param string[] $args
     *   Command arguments (everything after 'drush').
     * @param ?string $dir
     *   The working directory.
     * @param bool $mustRun
     *   Enable exceptions if the command fails.
     * @param bool $quiet
     *   Suppress command output.
     *
     * @return string|false
     */
    public function execute(array $args, ?string $dir = null, bool $mustRun = false, bool $quiet = true): string|false
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
    protected function getDrushExecutable(): string
    {
        if (isset($this->executable)) {
            return $this->executable;
        }

        if ($this->config->has('local.drush_executable')) {
            return $this->executable = $this->config->getStr('local.drush_executable');
        }

        // Find a locally installed Drush instance: first check the Composer
        // 'vendor' directory.
        $projectRoot = $this->localProject->getProjectRoot();
        $localDir = $projectRoot ?: getcwd();
        $drushLocal = $localDir . '/vendor/bin/drush';
        if (is_executable($drushLocal)) {
            return $this->executable = $drushLocal;
        }

        // Check the local dependencies directory (created via 'platform
        // build').
        $drushDep = $localDir . '/' . $this->config->getStr('local.dependencies_dir') . '/php/vendor/bin/drush';
        if (is_executable($drushDep)) {
            return $this->executable = $drushDep;
        }

        // Use the global Drush, if there is one installed.
        if ($this->shellHelper->commandExists('drush')) {
            return $this->executable = $this->shellHelper->resolveCommand('drush');
        }

        // Fall back to the Drush that may be installed within the CLI.
        $drushCli = CLI_ROOT . '/vendor/bin/drush';
        if (is_executable($drushCli)) {
            return $this->executable = $drushCli;
        }

        return $this->executable = 'drush';
    }

    /**
     * @return bool
     */
    public function clearCache(): bool
    {
        return $this->execute(['cache-clear', 'drush']) !== false;
    }

    /**
     * Gets existing Drush aliases for a group.
     *
     * @throws \Exception If the "drush sa" command fails.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAliases(string $groupName, bool $reset = false): array
    {
        if (!$reset && isset($this->aliases[$groupName])) {
            return $this->aliases[$groupName];
        }

        // Drush 9 uses 'site:alias', Drush <9 uses 'site-alias'. Fortunately
        // the alias 'sa' exists in both.
        $args = [$this->getDrushExecutable(), '@none', 'sa', '--format=json', '@' . $groupName];

        $aliases = [];

        // Run the command with a timeout. An exception will be thrown if it fails.
        // A user experienced timeouts when this was set to 5 seconds, so it was increased to 30.
        try {
            $result = $this->shellHelper->mustExecute($args, timeout: 30);
            $aliases = (array) json_decode($result, true);
        } catch (ProcessFailedException $e) {
            // The command will fail if the alias is not found. Throw an
            // exception for any other failures.
            if (stripos($e->getProcess()->getErrorOutput(), 'not found') === false) {
                throw $e;
            }
        }

        $this->aliases[$groupName] = $aliases;

        return $aliases;
    }

    /**
     * Gets the alias group from a project's local config file.
     */
    public function getAliasGroup(Project $project, string $projectRoot): string
    {
        $config = $this->localProject->getProjectConfig($projectRoot);

        return !empty($config['alias-group']) ? $config['alias-group'] : $project['id'];
    }

    /**
     * Sets and writes the alias group to the project's local config file.
     */
    public function setAliasGroup(string $newGroup, string $projectRoot): void
    {
        $this->localProject->writeCurrentProjectConfig(['alias-group' => $newGroup], $projectRoot, true);
    }

    /**
     * Create Drush aliases for the provided project and environments.
     *
     * @param Project       $project      The project
     * @param string        $projectRoot  The project root
     * @param Environment[] $environments The environments
     * @param ?string       $original     The original group name
     *
     * @return bool True on success, false on failure.
     */
    public function createAliases(Project $project, string $projectRoot, array $environments, ?string $original = null): bool
    {
        if (!$apps = $this->getDrupalApps($projectRoot)) {
            return false;
        }

        $group = $this->getAliasGroup($project, $projectRoot);

        $success = true;
        foreach ($this->getSiteAliasTypes() as $type) {
            $success = $success && $type->createAliases($project, $group, $apps, $environments, $original);
        }

        return $success;
    }

    /**
     * Finds Drupal applications in a project.
     *
     * @return LocalApplication[]
     */
    public function getDrupalApps(string $projectRoot): array
    {
        return array_filter(
            $this->applicationFinder->findApplications($projectRoot),
            fn(LocalApplication $app): bool => Drupal::isDrupal($app->getRoot()),
        );
    }

    /**
     * @return SiteAliasTypeInterface[]
     */
    protected function getSiteAliasTypes(): array
    {
        $types = [];
        $types[] = new DrushYaml($this->config, $this);
        $types[] = new DrushPhp($this->config, $this);

        return $types;
    }

    /**
     * Returns the site URL.
     */
    public function getSiteUrl(Environment $environment, LocalApplication $app): ?string
    {
        if ($this->api->hasCachedCurrentDeployment($environment)) {
            return $this->api->getSiteUrl($environment, $app->getName());
        }

        $urls = $environment->getRouteUrls();
        if (count($urls) === 1) {
            return reset($urls) ?: null;
        }

        return null;
    }

    public function deleteOldAliases(string $group): void
    {
        foreach ($this->getSiteAliasTypes() as $type) {
            $type->deleteAliases($group);
        }
    }
}
