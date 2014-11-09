<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Drupal extends ToolstackBase
{

    public $buildMode;

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

    public static function ensureDrushInstalled()
    {
        $drushVersion = shell_exec('drush version');
        if (strpos(strtolower($drushVersion), 'drush version') === false) {
            throw new \Exception('Drush must be installed.');
        }
        $versionParts = explode(':', $drushVersion);
        $versionNumber = trim($versionParts[1]);
        if (version_compare($versionNumber, '6.0') === -1) {
            throw new \Exception('Drush version must be 6.0 or newer.');
        }
    }

    public function detect($appRoot)
    {
        return self::isDrupal($appRoot, 0);
    }

    public function build()
    {
        $buildDir = $this->buildDir;

        // Options to pass to the drush command.
        $drushFlags = array();
        $drushFlags[] = '--yes';
        if (!empty($this->settings['verbosity'])) {
            $verbosity = $this->settings['verbosity'];
            if ($verbosity === OutputInterface::VERBOSITY_QUIET) {
                $drushFlags[] = '--quiet';
            }
            elseif ($verbosity === OutputInterface::VERBOSITY_DEBUG) {
                $drushFlags[] = '--debug';
            }
            elseif ($verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                $drushFlags[] = '--verbose';
            }
        }

        if (!empty($this->settings['drushConcurrency'])) {
            $drushFlags[] = '--concurrency=' . $this->settings['drushConcurrency'];
        }
        if (!empty($this->settings['drushWorkingCopy'])) {
            $drushFlags[] = '--working-copy';
        }

        // Flatten the options.
        $drushFlags = implode(' ', $drushFlags);

        $profiles = glob($this->appRoot . '/*.profile');
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the directory.");
        }

        $symlinkBlacklist = array(
          '.*',
          '*.make',
          'sites.php',
          'robots.txt',
          'config',
        );

        if (count($profiles) == 1) {
            $this->buildMode = 'profile';
            Drupal::ensureDrushInstalled();
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

            $drushCommand = "drush make $drushFlags " . escapeshellarg($projectCoreMake) . " " . escapeshellarg($buildDir);
            exec($drushCommand, $output, $return_var);
            if ($return_var > 0  || !is_dir($buildDir)) {
                throw new \Exception('Drush command failed: ' . $drushCommand);
            }
            // Drush will only create the $buildDir if the build succeeds.
            $profile = str_replace($this->appRoot, '', $profiles[0]);
            $profile = strtok($profile, '.');
            $profileDir = $buildDir . '/profiles/' . ltrim($profile, '/');
            // Drush Make requires $profileDir to not exist if it's passed
            // as the target. This chdir($this->appRoot) works around that.
            $cwd = getcwd();
            chdir($this->appRoot);
            $drushCommand = "drush make $drushFlags --no-core --contrib-destination=. " . escapeshellarg($projectMake);
            exec($drushCommand, $output, $return_var);
            chdir($cwd);
            if ($return_var > 0) {
                throw new \Exception('Drush command failed: ' . $drushCommand);
            }
            $symlinkBlacklist[] = 'settings*.php';
            $this->symlinkAll($this->appRoot, $profileDir, true, $symlinkBlacklist);
        } elseif (file_exists($this->appRoot . '/project.make')) {
            $this->buildMode = 'makefile';
            Drupal::ensureDrushInstalled();
            $projectMake = $this->appRoot . '/project.make';
            $drushCommand = "drush make $drushFlags " . escapeshellarg($projectMake) . " " . escapeshellarg($buildDir);
            exec($drushCommand, $output, $return_var);
            if ($return_var > 0 || !is_dir($buildDir)) {
                throw new \Exception('Drush command failed: ' . $drushCommand);
            }
            $this->symlinkAll($this->appRoot, $buildDir . '/sites/default', true, $symlinkBlacklist);
        }
        else {
            $this->buildMode = 'vanilla';
            $this->buildDir = $this->appRoot;
        }

        return true;
    }

    public function install()
    {
        $buildDir = $this->buildDir;

        // The build has been done, create a settings.php if it is missing.
        if (!file_exists($buildDir . '/sites/default/settings.php')) {
            copy(CLI_ROOT . '/resources/drupal/settings.php', $buildDir . '/sites/default/settings.php');
        }

        // Create the settings.local.php if it doesn't exist in either shared/
        // or in the app.
        if (!file_exists($this->projectRoot . '/shared/settings.local.php') && !file_exists($buildDir . '/sites/default/settings.local.php')) {
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

        $this->symlinkAll($this->projectRoot . '/shared', $buildDir . '/sites/default');

        // Point www to the latest build.
        $wwwLink = $this->projectRoot . '/www';
        $relBuildDir = $this->makePathRelative($buildDir, $wwwLink);
        $this->symlinkDir($this->absoluteLinks ? $buildDir : $relBuildDir, $wwwLink);
    }
}
