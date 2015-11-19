<?php

namespace Platformsh\Cli\Local\Toolstack;

use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Drupal extends ToolstackBase
{

    protected $drushFlags = array();

    public function getKey()
    {
        return 'php:drupal';
    }

    /**
     * Detect if there are any Drupal applications in a folder.
     *
     * @param string $directory
     * @param mixed  $depth
     *
     * @return bool
     */
    public static function isDrupal($directory, $depth = '< 2')
    {
        if (!is_dir($directory)) {
            return false;
        }

        $finder = new Finder();

        // Look for at least one Drush make file.
        $finder->in($directory)
               ->files()
               ->depth($depth)
               ->name('project.make*')
               ->name('drupal-org.make*');
        foreach ($finder as $file) {
            return true;
        }

        // Check whether there is an index.php file whose first few lines
        // contain the word "Drupal".
        $finder->in($directory)
               ->files()
               ->depth($depth)
               ->name('index.php');
        foreach ($finder as $file) {
            $f = fopen($file, 'r');
            $beginning = fread($f, 3178);
            fclose($f);
            if (strpos($beginning, 'Drupal') !== false) {
                return true;
            }
        }

        // Check whether there is a composer.json file requiring Drupal core.
        $finder->in($directory)
               ->files()
               ->depth($depth)
               ->name('composer.json');
        foreach ($finder as $file) {
            $composerJson = json_decode(file_get_contents($file), true);
            if (isset($composerJson['require']['drupal/core']) || isset($composerJson['require']['drupal/phing-drush-task'])) {
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
        $profiles = glob($this->appRoot . '/*.profile');
        $projectMake = $this->findDrushMakeFile();
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the directory.");
        } elseif (count($profiles) == 1) {
            $profileName = strtok(basename($profiles[0]), '.');
            $this->buildInProfileMode($profileName);
        } elseif ($projectMake) {
            $this->buildInProjectMode($projectMake);
        } else {
            $this->output->writeln("Building in vanilla mode: you are missing out!");

            $this->buildInPlace = true;

            if ($this->copy) {
                $this->fsHelper->copyAll($this->appRoot, $this->getBuildDir());
            }
            else {
                $this->copyGitIgnore('drupal/gitignore-vanilla');
                $this->checkIgnored('sites/default/settings.local.php');
                $this->checkIgnored('sites/default/files');
            }
        }

        $this->processSpecialDestinations();
    }

    /**
     * Check that an application file is ignored in .gitignore.
     *
     * @param string $filename
     * @param string $suggestion
     */
    protected function checkIgnored($filename, $suggestion = null)
    {
        if (empty($this->settings['projectRoot'])) {
            return;
        }
        $repositoryDir = $this->settings['projectRoot'] . '/' . LocalProject::REPOSITORY_DIR;
        $relative = $this->fsHelper->makePathRelative($this->appRoot . '/' . $filename, $repositoryDir);
        if (!$this->gitHelper->execute(array('check-ignore', $relative), $repositoryDir)) {
            $suggestion = $suggestion ?: $relative;
            $this->output->writeln("<comment>You should exclude this file using .gitignore:</comment> $suggestion");
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
            } elseif ($verbosity === OutputInterface::VERBOSITY_DEBUG) {
                $this->drushFlags[] = '--debug';
            } elseif ($verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->drushFlags[] = '--verbose';
            }
        }

        if (!empty($this->settings['drushWorkingCopy'])) {
            $this->drushFlags[] = '--working-copy';
        }

        if (!empty($this->settings['noCache'])) {
            $this->drushFlags[] = '--no-cache';
        } else {
            $this->drushFlags[] = '--cache-duration-releasexml=300';
        }

        if (!empty($this->settings['drushConcurrency'])) {
            $this->drushFlags[] = '--concurrency=' . $this->settings['drushConcurrency'];
        }
    }

    /**
     * Find the preferred Drush Make file in the app root.
     *
     * @param bool $required
     * @param bool $core
     *
     * @throws \Exception
     *
     * @return string|false
     *   The absolute filename of the make file.
     */
    protected function findDrushMakeFile($required = false, $core = false) {
        $candidates = array(
          'project.make.yml',
          'project.make',
          'drupal-org.make.yml',
          'drupal-org.make',
        );
        if (empty($this->settings['drushUpdateLock'])) {
            $candidates = array_merge(array(
              'project.make.lock',
              'project.make.yml.lock',
              'drupal-org.make.yml.lock',
              'drupal-org.make.lock',
            ), $candidates);
        }
        foreach ($candidates as &$candidate) {
            if ($core) {
                $candidate = str_replace('.make', '-core.make', $candidate);
            }
            if (file_exists($this->appRoot . '/' . $candidate)) {
                return $this->appRoot . '/' . $candidate;
            }
        }

        if ($required) {
            throw new \Exception(
              ($core ? "Couldn't find a core make file in the directory." : "Couldn't find a make file in the directory.")
              . " Possible filenames: " . implode(',', $candidates)
            );
        }

        return false;
    }

    /**
     * @return DrushHelper
     */
    protected function getDrushHelper()
    {
        static $drushHelper;
        if (!isset($drushHelper)) {
            $drushHelper = new DrushHelper($this->output);
        }

        return $drushHelper;
    }

    /**
     * Build in 'project' mode, i.e. just using a Drush make file.
     *
     * @param string $projectMake
     */
    protected function buildInProjectMode($projectMake)
    {
        $drushHelper = $this->getDrushHelper();
        $drushHelper->ensureInstalled();
        $this->setUpDrushFlags();

        $args = array_merge(
          array('make', $projectMake, $this->getBuildDir()),
          $this->drushFlags
        );

        // Create a lock file automatically.
        if (!strpos($projectMake, '.lock') && version_compare($drushHelper->getVersion(), '7.0.0-rc1', '>=') && !empty($this->settings['drushUpdateLock'])) {
            $args[] = "--lock=$projectMake.lock";
        }

        $drushHelper->execute($args, null, true, false);

        $this->processSettingsPhp();

        $this->ignoredFiles[] = '*.make';
        $this->ignoredFiles[] = '*.make.lock';
        $this->ignoredFiles[] = '*.make.yml';
        $this->ignoredFiles[] = '*.make.yml.lock';
        $this->ignoredFiles[] = 'settings.local.php';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        // Symlink, non-recursively, all files from the app into the
        // 'sites/default' directory.
        $this->fsHelper->symlinkAll(
          $this->appRoot,
          $this->getBuildDir() . '/sites/default',
          true,
          false,
          array_merge($this->ignoredFiles, array_keys($this->specialDestinations)),
          $this->copy
        );
    }

    /**
     * Build in 'profile' mode: the application contains a site profile.
     *
     * @param string $profileName
     */
    protected function buildInProfileMode($profileName)
    {
        $drushHelper = $this->getDrushHelper();
        $drushHelper->ensureInstalled();
        $this->setUpDrushFlags();

        $projectMake = $this->findDrushMakeFile(true);
        $projectCoreMake = $this->findDrushMakeFile(true, true);

        $args = array_merge(
          array('make', $projectCoreMake, $this->getBuildDir()),
          $this->drushFlags
        );

        // Create a lock file automatically.
        $updateLock = version_compare($drushHelper->getVersion(), '7.0.0-rc1', '>=') && !empty($this->settings['drushUpdateLock']);
        if (!strpos($projectCoreMake, '.lock') && $updateLock) {
            $args[] = "--lock=$projectCoreMake.lock";
        }

        $drushHelper->execute($args, null, true, false);

        $profileDir = $this->getBuildDir() . '/profiles/' . $profileName;
        mkdir($profileDir, 0755, true);

        $this->output->writeln("Building the profile: <info>$profileName</info>");

        $args = array_merge(
          array('make', '--no-core', '--contrib-destination=.', $projectMake),
          $this->drushFlags
        );

        // Create a lock file automatically.
        if (!strpos($projectMake, '.lock') && $updateLock) {
            $args[] = "--lock=$projectMake.lock";
        }

        $drushHelper->execute($args, $profileDir, true, false);

        if ($this->copy) {
            $this->output->writeln("Copying existing app files to the profile");
        }
        else {
            $this->output->writeln("Symlinking existing app files to the profile");
        }

        $this->ignoredFiles[] = '*.make';
        $this->ignoredFiles[] = '*.make.lock';
        $this->ignoredFiles[] = '*.make.yml';
        $this->ignoredFiles[] = '*.make.yml.lock';
        $this->ignoredFiles[] = 'settings.local.php';

        $this->specialDestinations['settings*.php'] = '{webroot}/sites/default';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        $this->processSettingsPhp();

        // Symlink recursively; skip existing files (built by Drush make) for
        // example 'modules/contrib', but include files from the app such as
        // 'modules/custom'.
        $this->fsHelper->symlinkAll(
          $this->appRoot,
          $profileDir,
          true,
          true,
          array_merge($this->ignoredFiles, array_keys($this->specialDestinations)),
          $this->copy
        );
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
        if ($this->copy) {
            // This behaviour only relates to symlinking.
            return;
        }
        $settingsPhpFile = $this->appRoot . '/settings.php';
        if (file_exists($settingsPhpFile)) {
            $this->output->writeln("Found a custom settings.php file: $settingsPhpFile");
            $this->fsHelper->copy($settingsPhpFile, $this->getBuildDir() . '/sites/default/settings.php');
            $this->output->writeln(
              "<comment>Your settings.php file has been copied (not symlinked) into the build directory."
              . "\nYou will need to rebuild if you edit this file.</comment>"
            );
            $this->ignoredFiles[] = 'settings.php';
        }
    }

    public function install()
    {
        $webRoot = $this->getWebRoot();
        $sitesDefault = $webRoot . '/sites/default';
        $resources = CLI_ROOT . '/resources/drupal';
        $shared = $this->getSharedDir();

        $defaultSettingsPhp = 'settings.php';
        $defaultSettingsLocal = 'settings.local.php';

        // Override settings.php for Drupal 8.
        if ($this->isDrupal8($webRoot)) {
            $defaultSettingsPhp = '8/settings.php';
        }

        // Create a settings.php if it is missing.
        if (is_dir($sitesDefault) && !file_exists($sitesDefault . '/settings.php')) {
            $this->fsHelper->copy($resources . '/' . $defaultSettingsPhp, $sitesDefault . '/settings.php');
        }

        // Create the shared/settings.local.php if it doesn't exist. Everything
        // in shared will be symlinked into sites/default.
        $settingsLocal = $shared . '/settings.local.php';
        if ($shared && !file_exists($settingsLocal)) {
            $this->output->writeln("Creating file: <info>$settingsLocal</info>");
            $this->fsHelper->copy($resources . '/' . $defaultSettingsLocal, $settingsLocal);
            $this->output->writeln('Edit this file to add your database credentials and other Drupal configuration.');
        }

        // Create a shared/files directory.
        $sharedFiles = "$shared/files";
        if ($shared && !file_exists($sharedFiles)) {
            $this->output->writeln("Creating directory: <info>$sharedFiles</info>");
            $this->output->writeln('This is where Drupal can store public files.');
            mkdir($sharedFiles);
            // Group write access is potentially useful and probably harmless.
            chmod($sharedFiles, 0775);
        }

        // Symlink all files and folders from shared. The "copy" option is
        // ignored, to avoid copying a huge sites/default/files directory every
        // time.
        if ($shared && is_dir($sitesDefault)) {
            $this->output->writeln("Symlinking files from the 'shared' directory to sites/default");
            $this->fsHelper->symlinkAll($shared, $sitesDefault);
        }
    }

    /**
     * Detect whether the site is Drupal 8.
     *
     * @param string $drupalRoot
     *
     * @return bool
     */
    protected function isDrupal8($drupalRoot)
    {
        return file_exists($drupalRoot . '/core/includes/bootstrap.inc');
    }
}
