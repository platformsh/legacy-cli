<?php

namespace CommerceGuys\Platform\Cli\Toolstack;

use CommerceGuys\Platform\Cli;
use Symfony\Component\Console;
use Symfony\Component\Finder\Finder;

class DrupalApp extends PhpApp implements LocalBuildInterface
{
    public static function detect($appRoot, $settings)
    {
        $finder = new Finder();
        // Search for Drupal Make files
        $finder->files()->name('project.make')
                        ->name('drupal-org.make')
                        ->name('drupal-org-core.make')
                        ->in($appRoot);
        foreach ($finder as $file) {
            return TRUE;
        }
        // Search for Drupal Copyright files
        $finder->files()->name('COPYRIGHT.txt')->in($appRoot);
        foreach ($finder as $file) {
            $f = fopen($file, 'r');
            $line = fgets($f);
            fclose($f);
            if (preg_match('#^All Drupal code#', $line) === 1) {
                return TRUE;
            }
        }

        return FALSE;
    }

    public function build()
    {
        $this->command->ensureDrushInstalled();
        $buildDir = $this->absBuildDir;
        $repositoryDir = $this->appRoot;
        $projectRoot = $this->settings['projectRoot'];

        // Options to pass to the drush command.
        $drushFlags = array();
        $drushFlags[] = '--yes';
        if ($this->command->input->getOption('working-copy')) {
            $drushFlags[] = '--working-copy';
        }
        if ($this->command->output->isQuiet()) {
            $drushFlags[] = '--quiet';
        }
        elseif ($this->command->output->isDebug()) {
            $drushFlags[] = '--debug';
        }
        elseif ($this->command->output->isVerbose()) {
            $drushFlags[] = '--verbose';
        }
        // Flatten the options.
        $drushFlags = implode(' ', $drushFlags);

        $profiles = glob($repositoryDir . '/*.profile');
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the repository.");
        } elseif (count($profiles) == 1) {
            // Find the contrib make file.
            if (file_exists($repositoryDir . '/project.make')) {
                $projectMake = $repositoryDir . '/project.make';
            } elseif (file_exists($repositoryDir . '/drupal-org.make')) {
                $projectMake = $repositoryDir . '/drupal-org.make';
            } else {
                throw new \Exception("Couldn't find a project.make or drupal-org.make in the repository.");
            }
            // Find the core make file.
            if (file_exists($repositoryDir . '/project-core.make')) {
                $projectCoreMake = $repositoryDir . '/project-core.make';
            } elseif (file_exists($repositoryDir . '/drupal-org-core.make')) {
                $projectCoreMake = $repositoryDir . '/drupal-org-core.make';
            } else {
                throw new \Exception("Couldn't find a project-core.make or drupal-org-core.make in the repository.");
            }

            shell_exec("drush make $drushFlags $projectCoreMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
                $profile = str_replace($repositoryDir, '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $buildDir . '/profiles/' . $profile;
                symlink($repositoryDir, $profileDir);
                // Drush Make requires $profileDir to not exist if it's passed
                // as the target. chdir($profileDir) works around that.
                chdir($profileDir);
                shell_exec("drush make $drushFlags --no-core --contrib-destination=. $projectMake");
            }
        } elseif (file_exists($repositoryDir . '/project.make')) {
            $projectMake = $repositoryDir . '/project.make';
            shell_exec("drush make $drushFlags $projectMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
              // Remove sites/default to make room for the symlink.
              $this->rmdir($buildDir . '/sites/default');
              $this->symlink($repositoryDir, $buildDir . '/sites/default');
            }
        }
        else {
            // Nothing to build.
            return;
        }

        // The build has been done, create a settings.php if it is missing.
        if (is_dir($buildDir) && !file_exists($buildDir . '/sites/default/settings.php')) {
            // Create the settings.php file.
            copy(CLI_ROOT . '/resources/drupal/settings.php', $buildDir . '/sites/default/settings.php');
        }

        // Symlink all files and folders from shared.
        // @todo: Figure out a way to split up local shared resources by application.

        $this->symlink($projectRoot . '/shared', $buildDir . '/sites/default');

        // Point www to the latest build.
        $wwwLink = $projectRoot . '/www';
        if (file_exists($wwwLink) || is_link($wwwLink)) {
            // @todo Windows might need rmdir instead of unlink.
            unlink($wwwLink);
        }
        symlink($this->absoluteLinks ? $this->absBuildDir : $this->relBuildDir, $wwwLink);

    }
}
