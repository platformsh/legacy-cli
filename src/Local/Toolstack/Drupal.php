<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use CommerceGuys\Platform\Cli\Helper\DrushHelper;
use CommerceGuys\Platform\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Drupal extends ToolstackBase
{

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

        // Look for at least one Drush make file.
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

        // Check whether there is an index.php file whose first few lines
        // contain the word "Drupal".
        $finder->in($directory)->files()->depth($depth)->name('index.php');
        foreach ($finder as $file) {
            $f = fopen($file, 'r');
            $beginning = fread($f, 3178);
            fclose($f);
            if (strpos($beginning, 'Drupal') !== false) {
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
            $this->buildDir = $this->appRoot;
            $this->specialDestinations = array();
            $this->preventArchive = true;

            $this->copyGitIgnore('drupal/gitignore-vanilla');

            $this->checkIgnored('sites/default/settings.local.php');
            $this->checkIgnored('sites/default/files');
        }

        $this->symLinkSpecialDestinations();
    }

    /**
     * Check that an application file is ignored in .gitignore.
     *
     * @param string $filename
     * @param string $suggestion
     */
    protected function checkIgnored($filename, $suggestion = null)
    {
        $repositoryDir = $this->projectRoot . '/' . LocalProject::REPOSITORY_DIR;
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
            }
            elseif ($verbosity === OutputInterface::VERBOSITY_DEBUG) {
                $this->drushFlags[] = '--debug';
            }
            elseif ($verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->drushFlags[] = '--verbose';
            }
        }

        if (!empty($this->settings['drushWorkingCopy'])) {
            $this->drushFlags[] = '--working-copy';
        }

        if (!empty($this->settings['noCache'])) {
            $this->drushFlags[] = '--no-cache';
        }
        else {
            $this->drushFlags[] = '--cache-duration-releasexml=300';
        }

        $concurrency = isset($this->settings['drushConcurrency']) ? $this->settings['drushConcurrency'] : 8;
        $this->drushFlags[] = '--concurrency=' . $concurrency;
    }

    /**
     * Build in 'project' mode, i.e. just using a Drush make file.
     *
     * @param string $projectMake
     */
    protected function buildInProjectMode($projectMake)
    {
        $drushHelper = new DrushHelper($this->output);
        $drushHelper->ensureInstalled();
        $args = array_merge(
          array('make', $projectMake, $this->buildDir),
          $this->drushFlags
        );
        $drushHelper->execute($args, null, true, false);

        $this->processSettingsPhp();

        $this->ignoredFiles[] = 'project.make';
        $this->ignoredFiles[] = 'settings.local.php';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        // Symlink, non-recursively, all files from the app into the
        // 'sites/default' directory.
        $this->fsHelper->symlinkAll(
          $this->appRoot,
          $this->buildDir . '/sites/default',
          true,
          false,
          array_merge($this->ignoredFiles, array_keys($this->specialDestinations))
        );
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

        $profileDir = $this->buildDir . '/profiles/' . $profileName;
        mkdir($profileDir, 0755, true);

        $this->output->writeln("Building the profile: <info>$profileName</info>");

        $args = array_merge(
          array('make', '--no-core', '--contrib-destination=.', $projectMake),
          $this->drushFlags
        );
        $drushHelper->execute($args, $profileDir, true, false);

        $this->ignoredFiles[] = basename($projectMake);
        $this->ignoredFiles[] = basename($projectCoreMake);
        $this->ignoredFiles[] = 'settings.local.php';

        $this->specialDestinations['settings*.php'] = '{webroot}/sites/default';
        $this->specialDestinations['sites.php'] = '{webroot}/sites';

        $this->processSettingsPhp();

        $this->output->writeln("Symlinking existing app files to the profile");

        $this->profileModeBcWarning($profileDir);

        // Symlink recursively; skip existing files (built by Drush make) for
        // example 'modules/contrib', but include files from the app such as
        // 'modules/custom'.
        $this->fsHelper->symlinkAll(
          $this->appRoot,
          $profileDir,
          true,
          true,
          array_merge($this->ignoredFiles, array_keys($this->specialDestinations))
        );
    }

    /**
     * Backwards compatibility warning for profile builds.
     *
     * Profile builds changed between v1.9.0 and v1.9.1. This provides a warning
     * to show that users should not have files that are both in their
     * repository and in Drush Make.
     *
     * @param string $profileDir
     *
     * @todo remove this in later versions
     */
    protected function profileModeBcWarning($profileDir)
    {
        $conflicts = $this->findDrushMakeConflicts($profileDir, '', 10, true);
        if (count($conflicts)) {
            $this->output->writeln("\n<comment>Profile builds have changed.</comment>");
            $this->output->writeln('Files are no longer built inside the repository directory.');
            $this->output->writeln('You should ensure that your repository directory does not contain files that are also built by Drush Make.');
            $this->output->writeln('Examples:');
            $this->output->writeln('  ' . implode("\n  ", $conflicts) . "\n");
        }
    }

    /**
     * Find files in the Drush Make output that also exist in the app.
     *
     * @param string $dir
     * @param string $subDir
     * @param int    $limit
     * @param bool   $checkIgnored
     *
     * @todo remove this
     *
     * @return array
     */
    protected function findDrushMakeConflicts($dir, $subDir = '', $limit = 0, $checkIgnored = false)
    {
        $conflicts = array();
        $found = 0;
        $subDirAbsolute = rtrim($dir . '/' . $subDir, '/');
        $subDirRelative = $subDir ? $subDir . '/' : '';
        $repositoryDir = $this->projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $handle = opendir($subDirAbsolute);
        while (false !== ($filename = readdir($handle))) {
            if ($filename[0] === '.') {
                continue;
            }
            if ($limit && $found >= $limit) {
                break;
            }
            if (is_dir($subDirAbsolute . '/' . $filename)) {
                $conflicts += $this->findDrushMakeConflicts($dir, $subDirRelative . $filename, $limit >= 0 ? $limit - $found : 0, $checkIgnored);
                continue;
            }
            $appFile = $this->appRoot . '/' . $subDirRelative . $filename;
            if (file_exists($appFile)) {
                if ($checkIgnored) {
                    $relative = $this->fsHelper->makePathRelative($appFile, $repositoryDir);
                    if (!$this->gitHelper->execute(array('check-ignore', $relative))) {
                        continue;
                    }
                }
                $conflicts[] = $this->appRoot . '/' . $subDirRelative . $filename;
                $found++;
            }
        }
        closedir($handle);
        return $conflicts;
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
        $sitesDefault = $buildDir . '/sites/default';
        $resources = CLI_ROOT . '/resources/drupal';
        $shared = $this->getSharedDir();

        // The build has been done: create a settings.php if it is missing.
        if (!file_exists($sitesDefault . '/settings.php')) {
            copy($resources . '/settings.php', $sitesDefault . '/settings.php');
        }

        // Create the shared/settings.local.php if it doesn't exist. Everything
        // in shared will be symlinked into sites/default.
        $settingsLocal = $shared . '/settings.local.php';
        if (!file_exists($settingsLocal)) {
            $this->output->writeln("Creating file: <info>$settingsLocal</info>");
            copy($resources . '/settings.local.php', $settingsLocal);
            $this->output->writeln('Edit this file to add your database credentials and other Drupal configuration.');
        }

        // Create a shared/files directory.
        $sharedFiles = "$shared/files";
        if (!file_exists($sharedFiles)) {
            $this->output->writeln("Creating directory: <info>$sharedFiles</info>");
            $this->output->writeln('This is where Drupal can store public files.');
            mkdir($sharedFiles);
            // Group write access is potentially useful and probably harmless.
            chmod($sharedFiles, 0775);
        }

        // Symlink all files and folders from shared.
        $this->output->writeln("Symlinking files from the 'shared' directory to sites/default");
        $this->fsHelper->symlinkAll($shared, $sitesDefault);
    }

}
