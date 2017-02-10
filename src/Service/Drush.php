<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class Drush
{
    /** @var string */
    protected $homeDir;

    /** @var Shell */
    protected $shellHelper;

    /** @var LocalProject */
    protected $localProject;

    /** @var Filesystem */
    protected $fs;

    /** @var Config */
    protected $config;

    /**
     * @param Config|null       $config
     * @param Shell|null        $shellHelper
     * @param LocalProject|null $localProject
     * @param Filesystem|null   $fs
     */
    public function __construct(
        Config $config = null,
        Shell $shellHelper = null,
        LocalProject $localProject = null,
        Filesystem $fs = null
    ) {
        $this->shellHelper = $shellHelper ?: new Shell();
        $this->config = $config ?: new Config();
        $this->localProject = $localProject ?: new LocalProject();
        $fs = $fs ?: new Filesystem();
        $this->homeDir = $fs->getHomeDirectory();
    }

    /**
     * @param string $homeDir
     */
    public function setHomeDir($homeDir)
    {
        $this->homeDir = $homeDir;
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
        $command = $this->getDrushExecutable() . ' --version';
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

        return $this->shellHelper->resolveCommand('drush');
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        return (bool) $this->execute(['cache-clear', 'drush']);
    }

    /**
     * @param string $groupName
     *
     * @return string|bool
     */
    public function getAliases($groupName)
    {
        return $this->execute(
            [
                '@none',
                'site-alias',
                '--pipe',
                '--format=list',
                '@' . $groupName,
            ]
        );
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
     * Create Drush aliases for the provided project and environments.
     *
     * @param Project       $project      The project
     * @param string        $projectRoot  The project root
     * @param Environment[] $environments The environments
     * @param string        $original     The original group name
     * @param bool          $merge        Whether to merge existing alias settings
     *
     * @throws \Exception
     *
     * @return bool Whether any aliases have been created.
     */
    public function createAliases(Project $project, $projectRoot, $environments, $original = null, $merge = true)
    {
        $config = $this->localProject->getProjectConfig($projectRoot);
        $group = !empty($config['alias-group']) ? $config['alias-group'] : $project['id'];
        $autoRemoveKey = $this->getAutoRemoveKey();

        // Ensure the existence of the .drush directory.
        $drushDir = $this->homeDir . '/.drush';
        if (!is_dir($drushDir)) {
            mkdir($drushDir);
        }

        $filename = $drushDir . '/' . $group . '.aliases.drushrc.php';
        if (!is_writable($drushDir) || (file_exists($filename) && !is_writable($filename))) {
            throw new \Exception("Drush alias file not writable: $filename");
        }

        // Include the previous alias file(s) so that the user's own
        // modifications can be merged. This may create a PHP parse error for
        // invalid syntax, but in that case the user could not run Drush anyway.
        $aliases = [];
        $originalFiles = [$filename];
        if ($original) {
            array_unshift($originalFiles, $drushDir . '/' . $original . '.aliases.drushrc.php');
        }
        if ($merge) {
            foreach ($originalFiles as $originalFile) {
                if (file_exists($originalFile)) {
                    include $originalFile;
                }
            }
        }

        // Gather applications.
        $apps = LocalApplication::getApplications($projectRoot, $this->config);
        $drupalApps = $apps;
        $multiApp = false;
        if (count($apps) > 1) {
            $multiApp = true;
            // Remove non-Drupal applications.
            foreach ($drupalApps as $key => $app) {
                if (!Drupal::isDrupal($app->getRoot())) {
                    unset($drupalApps[$key]);
                }
            }
        }

        // Generate aliases for the remote environments and applications.
        $autoGenerated = '';
        foreach ($environments as $environment) {
            foreach ($drupalApps as $app) {
                $newAlias = $this->generateRemoteAlias($environment, $app, $multiApp);
                if (!$newAlias) {
                    continue;
                }

                $aliasName = $environment->id;
                if (count($drupalApps) > 1) {
                    $aliasName .= '--' . $app->getId();
                }

                // If the alias already exists, recursively replace existing
                // settings with new ones.
                if (isset($aliases[$aliasName])) {
                    $newAlias = array_replace_recursive($aliases[$aliasName], $newAlias);
                    unset($aliases[$aliasName]);
                }

                $autoGenerated .= sprintf(
                    "\n// Automatically generated alias for the environment \"%s\", application \"%s\".\n",
                    $environment->title,
                    $app->getId()
                );
                $autoGenerated .= $this->exportAlias($aliasName, $newAlias);
            }
        }

        // Generate an alias for the local environment, for each app.
        $localAlias = '';
        $localWebRoot = $this->config->get('local.web_root');
        foreach ($drupalApps as $app) {
            $appId = $app->getId();
            $localAliasName = '_local';
            $webRoot = $projectRoot . '/' . $localWebRoot;
            if (count($drupalApps) > 1) {
                $localAliasName .= '--' . $appId;
            }
            if ($multiApp) {
                $webRoot .= '/' . $appId;
            }
            $local = [
                'root' => $webRoot,
                $autoRemoveKey => true,
            ];
            if (isset($aliases[$localAliasName])) {
                $local = array_replace_recursive($aliases[$localAliasName], $local);
                unset($aliases[$localAliasName]);
            }
            $localAlias .= "\n"
                . sprintf('// Automatically generated alias for the local environment, application "%s"', $appId)
                . "\n"
                . $this->exportAlias($localAliasName, $local);
        }

        // Add any user-defined (pre-existing) aliases.
        $userDefined = '';
        foreach ($aliases as $name => $alias) {
            if (!empty($alias[$autoRemoveKey])) {
                // This is probably for a deleted environment.
                continue;
            }
            $userDefined .= $this->exportAlias($name, $alias) . "\n";
        }
        if ($userDefined) {
            $userDefined = "\n// User-defined aliases.\n" . $userDefined;
        }

        $header = "<?php\n"
            . "/**\n * @file"
            . "\n * Drush aliases for the " . $this->config->get('service.name') . " project \"{$project->title}\"."
            . "\n *"
            . "\n * This file is auto-generated by the " . $this->config->get('application.name') . "."
            . "\n *"
            . "\n * WARNING"
            . "\n * This file may be regenerated at any time."
            . "\n * - User-defined aliases will be preserved."
            . "\n * - Aliases for active environments (including any custom additions) will be preserved."
            . "\n * - Aliases for deleted or inactive environments will be deleted."
            . "\n * - All other information will be deleted."
            . "\n */\n\n";

        $export = $header . $userDefined . $localAlias . $autoGenerated;

        $this->writeAliasFile($filename, $export);

        return true;
    }

    /**
     * Write a file and create a backup if the contents have changed.
     *
     * @param string $filename
     * @param string $contents
     */
    protected function writeAliasFile($filename, $contents)
    {
        $fs = new SymfonyFilesystem();
        if (is_readable($filename) && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . str_replace('.php', '.bak.php', basename($filename));
            $fs->rename($filename, $backupName, true);
        }
        $fs->dumpFile($filename, $contents);
    }

    /**
     * @param string $name
     * @param array  $alias
     *
     * @return string
     */
    protected function exportAlias($name, array $alias)
    {
        return "\$aliases['" . str_replace("'", "\\'", $name) . "'] = " . var_export($alias, true) . ";\n";
    }

    /**
     * @param Environment $environment
     * @param LocalApplication $app
     * @param bool $multiApp
     *
     * @return array|false
     */
    protected function generateRemoteAlias($environment, $app, $multiApp = false)
    {
        if (!$environment->hasLink('ssh') || !$environment->hasLink('public-url')) {
            return false;
        }
        $sshUrl = parse_url($environment->getLink('ssh'));
        if (!$sshUrl) {
            return false;
        }
        $sshUser = $sshUrl['user'];
        if ($multiApp) {
            $sshUser .= '--' . $app->getName();
        }

        $uri = $environment->getLink('public-url');
        if ($multiApp) {
            $guess = str_replace('http://', 'http://' . $app->getName() . '---', $uri);
            if (in_array($guess, $environment->getRouteUrls())) {
                $uri = $guess;
            }
        }

        return [
            'uri' => $uri,
            'remote-host' => $sshUrl['host'],
            'remote-user' => $sshUser,
            'root' => '/app/' . $app->getDocumentRoot(),
            $this->getAutoRemoveKey() => true,
            'command-specific' => [
                'site-install' => [
                    'sites-subdir' => 'default',
                ],
            ],
        ];
    }
}
