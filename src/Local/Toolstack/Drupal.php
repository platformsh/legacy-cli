<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use CommerceGuys\Platform\Cli\Helper\DrushHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Drupal extends ToolstackBase
{

    public $buildMode;

    protected $drushFlags = array();

    public function getKey() {
        return 'php:drupal';
    }

    /**
     * Detect if there are any Drupal applications in a folder.
     *
     * @param string $directory
     * @param mixed $depth
     *
     * @return bool
     */
    public static function isDrupal($directory, $depth = '< 2') {
        $finder = new Finder();
        $finder->in($directory)
            ->files()
            ->depth($depth)
            ->name('project.make')
            ->name('project-core.make')
            ->name('drupal-org.make')
            ->name('drupal-org-core.make');
        foreach ($finder as $file) {
            return true;
        }
        $finder->in($directory)->files()->depth($depth)->name('COPYRIGHT.txt');
        foreach ($finder as $file) {
            $f = fopen($file, 'r');
            $line = fgets($f);
            fclose($f);
            if (preg_match('#^All Drupal code#', $line) === 1) {
                return true;
            }
        }
        return false;
    }

    public function detect($appRoot)
    {
        return self::isDrupal($appRoot, 0);
    }

    public function build()
    {
        $this->setUpDrushFlags();

        $profiles = glob($this->appRoot . '/*.profile');
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the directory.");
        }
        elseif (count($profiles) == 1) {
            $profileName = strtok(basename($profiles[0]), '.');
            $this->buildInProfileMode($profileName);
        }
        elseif (file_exists($this->appRoot . '/project.make')) {
            $this->buildInProjectMode($this->appRoot . '/project.make');
        }
        else {
            $this->output->writeln("Building in vanilla mode: you are missing out!");
            $this->buildMode = 'vanilla';
            $this->buildDir = $this->appRoot;
        }
    }

    /**
     * Set up options to pass to the drush commands.
     */
    protected function setUpDrushFlags()
    {
        $this->drushFlags = array();
        $this->drushFlags[] = '--yes';
        if (!empty($this->settings['verbosity'])) {
            $verbosity = $this->settings['verbosity'];
            if ($verbosity === OutputInterface::VERBOSITY_QUIET) {
                $this->drushFlags[] = '--quiet';
            }
            elseif ($verbosity === OutputInterface::VERBOSITY_DEBUG) {
                $this->drushFlags[] = '--debug';
            }
            elseif ($verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->drushFlags[] = '--verbose';
            }
        }

        if (!empty($this->settings['drushConcurrency'])) {
            $this->drushFlags[] = '--concurrency=' . $this->settings['drushConcurrency'];
        }
        if (!empty($this->settings['drushWorkingCopy'])) {
            $this->drushFlags[] = '--working-copy';
        }
        if (!empty($this->settings['noCache'])) {
            $this->drushFlags[] = '--no-cache';
        }
    }

    /**
     * Build in 'project' mode, i.e. just using a Drush make file.
     *
     * @param string $projectMake
     */
    protected function buildInProjectMode($projectMake)
    {
        $drushHelper = new DrushHelper($this->output);
        $this->buildMode = 'project';
        $drushHelper->ensureInstalled();
        $args = array_merge(
          array('make', $projectMake, $this->buildDir),
          $this->drushFlags
        );
        $drushHelper->execute($args, null, true, false);

        $this->processSettingsPhp();

        $this->ignoredFiles[] = 'project.make';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        $this->fsHelper->symlinkAll($this->appRoot, $this->buildDir . '/sites/default', true, array_merge($this->ignoredFiles, array_keys($this->specialDestinations)));
    }

    /**
     * Build in 'profile' mode: the application contains a site profile.
     *
     * @param string $profileName
     *
     * @throws \Exception
     */
    protected function buildInProfileMode($profileName)
    {
        $drushHelper = new DrushHelper($this->output);
        $this->buildMode = 'profile';
        $drushHelper->ensureInstalled();

        // Find the contrib make file.
        if (file_exists($this->appRoot . '/project.make')) {
            $projectMake = $this->appRoot . '/project.make';
        } elseif (file_exists($this->appRoot . '/drupal-org.make')) {
            $projectMake = $this->appRoot . '/drupal-org.make';
        } else {
            throw new \Exception("Couldn't find a project.make or drupal-org.make in the directory.");
        }

        // Find the core make file.
        if (file_exists($this->appRoot . '/project-core.make')) {
            $projectCoreMake = $this->appRoot . '/project-core.make';
        } elseif (file_exists($this->appRoot . '/drupal-org-core.make')) {
            $projectCoreMake = $this->appRoot . '/drupal-org-core.make';
        } else {
            throw new \Exception("Couldn't find a project-core.make or drupal-org-core.make in the directory.");
        }

        $args = array_merge(
          array('make', $projectCoreMake, $this->buildDir),
          $this->drushFlags
        );
        $drushHelper->execute($args, null, true, false);

        // Drush will only create the $buildDir if the build succeeds.
        $profileDir = $this->buildDir . '/profiles/' . $profileName;

        $args = array_merge(
          array('make', '--no-core', '--contrib-destination=.', $projectMake),
          $this->drushFlags
        );
        $drushHelper->execute($args, $this->appRoot, true, false);

        $this->ignoredFiles[] = $projectMake;
        $this->ignoredFiles[] = $projectCoreMake;

        $this->specialDestinations['settings*.php'] = '{webroot}/sites/default';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        $this->processSettingsPhp();

        $this->fsHelper->symlinkAll($this->appRoot, $profileDir, true, array_merge($this->ignoredFiles, array_keys($this->specialDestinations)));
    }

    /**
     * Handle a custom settings.php file for project and profile mode.
     *
     * If the user has a custom settings.php file, and we symlink it into
     * sites/default, then it will probably fail to pick up
     * settings.local.php from the right place. So we need to copy the
     * settings.php instead of symlinking it.
     *
     * See https://github.com/platformsh/platformsh-cli/issues/175
     */
    protected function processSettingsPhp()
    {
        $settingsPhpFile = $this->appRoot . '/settings.php';
        if (file_exists($settingsPhpFile)) {
            $this->output->writeln("Found a custom settings.php file: $settingsPhpFile");
            copy($settingsPhpFile, $this->buildDir . '/sites/default/settings.php');
            $this->output->writeln(
              "<comment>Your settings.php file has been copied (not symlinked) into the build directory."
              . "\nYou will need to rebuild if you edit this file.</comment>");
            $this->ignoredFiles[] = 'settings.php';
        }
    }

    public function install()
    {
        $buildDir = $this->buildDir;

        // The build has been done, create a settings.php if it is missing.
        if (!file_exists($buildDir . '/sites/default/settings.php') && file_exists($buildDir . '/sites/default')) {
            copy(CLI_ROOT . '/resources/drupal/settings.php', $buildDir . '/sites/default/settings.php');
        }

        // Create the settings.local.php if it doesn't exist in either shared/
        // or in the app.
        if (!file_exists($this->projectRoot . '/shared/settings.local.php') && file_exists($buildDir . '/sites/default') && !file_exists($buildDir . '/sites/default/settings.local.php')) {
            copy(CLI_ROOT . '/resources/drupal/settings.local.php', $this->projectRoot . '/shared/settings.local.php');
        }

        // Create a shared/files directory.
        if (!file_exists($this->projectRoot . '/shared/files')) {
            mkdir($this->projectRoot . '/shared/files');
            // Group write access is potentially useful and probably harmless.
            chmod($this->projectRoot . '/shared/files', 0775);
        }

        // Create a .gitignore file if it's missing, and if this app is the
        // whole repository.
        if ($this->appRoot == $this->projectRoot . '/repository' && !file_exists($this->projectRoot . '/repository/.gitignore')) {
            // There is a different default gitignore file for each build mode.
            copy(CLI_ROOT . '/resources/drupal/gitignore-' . $this->buildMode, $this->projectRoot . '/repository/.gitignore');
        }

        // Symlink all files and folders from shared.
        // @todo: Figure out a way to split up local shared resources by application.

        $this->fsHelper->symlinkAll($this->projectRoot . '/shared', $buildDir . '/sites/default');

        $this->symLinkSpecialDestinations();

        // Point www to the latest build.
        $this->fsHelper->symLink($buildDir, $this->projectRoot . '/www');
    }

}
