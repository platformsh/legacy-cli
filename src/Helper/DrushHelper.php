<?php

namespace Platformsh\Cli\Helper;

use Platformsh\Cli\Console\OutputAwareInterface;
use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Exception\DependencyVersionMismatchException;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class DrushHelper extends Helper implements OutputAwareInterface
{

    protected $homeDir = '~';

    /** @var ShellHelperInterface */
    protected $shellHelper;

    /** @var Filesystem */
    protected $fs;

    public function getName()
    {
        return 'drush';
    }

    /**
     * @param ShellHelperInterface $shellHelper
     * @param object               $fs
     */
    public function __construct(ShellHelperInterface $shellHelper = null, $fs = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
        $this->fs = $fs ?: new Filesystem();
    }

    /**
     * {@inheritdoc}
     */
    public function setOutput(OutputInterface $output)
    {
        if ($this->shellHelper instanceof OutputAwareInterface) {
            $this->shellHelper->setOutput($output);
        }
    }

    /**
     * Get the installed Drush version.
     *
     * @param bool $reset
     *
     * @return string
     *
     * @throws \Exception
     *   If the version can't be found.
     * @throws DependencyMissingException
     *   If Drush is not installed.
     */
    public function getVersion($reset = false)
    {
        static $version;
        if (!$reset && isset($version)) {
            return $version;
        }
        $command = $this->getDrushExecutable() . ' --version';
        exec($command, $drushVersion, $returnCode);
        if ($returnCode > 0) {
            if ($returnCode === 127) {
                throw new DependencyMissingException('Drush is not installed');
            }
            throw new \Exception("Error finding Drush version using command '$command'");
        }

        // Parse the version from the Drush output. It should be a string a bit
        // like " Drush Version   :  8.0.0-beta14 ".
        if (!preg_match('/[:\s]\s*([0-9]+\.[a-z0-9\-\.]+)\s*$/', $drushVersion[0], $matches)) {
            throw new \Exception("Unexpected output from command '$command': \n" . implode("\n", $drushVersion));
        }
        $version = $matches[1];

        return $version;
    }

    /**
     * @param string $minVersion
     * @param bool   $reset
     *
     * @throws DependencyVersionMismatchException
     */
    public function ensureInstalled($minVersion = '6', $reset = false)
    {
        $version = $this->getVersion($reset);
        if ($minVersion && version_compare($version, $minVersion, '<')) {
            throw new DependencyVersionMismatchException(
                sprintf('Drush version %s found, but %s (or later) is required', $version, $minVersion)
            );
        }
    }

    /**
     * Set the user's home directory.
     *
     * @param string $homeDir
     */
    public function setHomeDir($homeDir)
    {
        $this->homeDir = $homeDir;
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
     */
    public function getDrushExecutable()
    {
        if (getenv(CLI_ENV_PREFIX . 'DRUSH')) {
            return getenv(CLI_ENV_PREFIX . 'DRUSH');
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
        return preg_replace('/[^a-z-]+/', '-', str_replace('.', '', strtolower(CLI_NAME))) . '-auto-remove';
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
        $localProject = new LocalProject();
        $config = $localProject->getProjectConfig($projectRoot);
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
        $apps = LocalApplication::getApplications($projectRoot);
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
        foreach ($drupalApps as $app) {
            $appId = $app->getId();
            $localAliasName = '_local';
            $webRoot = $projectRoot . '/' . CLI_LOCAL_WEB_ROOT;
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
            $localAlias .= sprintf("\n// Automatically generated alias for the local environment, application \"%s\".\n", $appId)
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
            . "\n * Drush aliases for the " . CLI_CLOUD_SERVICE . " project \"{$project->title}\"."
            . "\n *"
            . "\n * This file is auto-generated by the " . CLI_NAME . "."
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
        if (is_readable($filename) && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . str_replace('.php', '.bak.php', basename($filename));
            $this->fs->rename($filename, $backupName, true);
        }
        $this->fs->dumpFile($filename, $contents);
    }

    /**
     * @param string $name
     * @param array  $alias
     *
     * @return string
     */
    protected function exportAlias($name, array $alias)
    {
        return "\$aliases['" . $name . "'] = " . var_export($alias, true) . ";\n";
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
