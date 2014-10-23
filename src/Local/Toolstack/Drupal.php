<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class Drupal extends ToolstackBase
{

    protected $isVanilla = false;

    public function getName()
    {
        return 'PHP/Drupal';
    }

    public function getKey() {
        return 'php:drupal';
    }

    /**
     * Detect if there are any Drupal applications in a folder.
     *
     * @param string $directory
     * @param string $depth
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
        $buildDir = $this->absBuildDir;

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
        } elseif (count($profiles) == 1) {
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

            shell_exec("drush make $drushFlags " . escapeshellarg($projectCoreMake) . " " . escapeshellarg($buildDir));
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
                $profile = str_replace($this->appRoot, '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $buildDir . '/profiles/' . $profile;
                symlink($this->appRoot, $profileDir);
                // Drush Make requires $profileDir to not exist if it's passed
                // as the target. chdir($profileDir) works around that.
                chdir($profileDir);
                shell_exec("drush make $drushFlags --no-core --contrib-destination=. " . escapeshellarg($projectMake));
            }
        } elseif (file_exists($this->appRoot . '/project.make')) {
            $projectMake = $this->appRoot . '/project.make';
            shell_exec("drush make $drushFlags " . escapeshellarg($projectMake) . " " . escapeshellarg($buildDir));
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
              // Remove sites/default to make room for the symlink.
              $this->rmdir($buildDir . '/sites/default');
              $this->symlink($this->appRoot, $buildDir . '/sites/default');
            }
        }
        else {
            $this->isVanilla = true;
        }

        return true;
    }

    public function install()
    {
        $buildDir = $this->isVanilla ? $this->appRoot : $this->absBuildDir;

        // @todo relative link for vanilla projects
        $relBuildDir = $this->isVanilla ? $this->appRoot : $this->relBuildDir;

        // The build has been done, create a settings.php if it is missing.
        if (is_dir($buildDir) && !file_exists($buildDir . '/sites/default/settings.php')) {
            // Create the settings.php file.
            copy(CLI_ROOT . '/resources/drupal/settings.php', $buildDir . '/sites/default/settings.php');
        }

        // Symlink all files and folders from shared.
        // @todo: Figure out a way to split up local shared resources by application.

        $this->symlink($this->projectRoot . '/shared', $buildDir . '/sites/default');

        // Point www to the latest build.
        $wwwLink = $this->projectRoot . '/www';
        if (file_exists($wwwLink) || is_link($wwwLink)) {
            // @todo Windows might need rmdir instead of unlink.
            unlink($wwwLink);
        }
        symlink($this->absoluteLinks ? $buildDir : $relBuildDir, $wwwLink);
    }
}
