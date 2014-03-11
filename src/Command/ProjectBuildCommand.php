<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectBuildCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:build')
            ->setAliases(array('build'))
            ->setDescription('Builds the current project.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return;
        }
        $project = $this->getCurrentProject();
        $environment = $this->getCurrentEnvironment($project);
        if (!$environment) {
            $output->writeln("<error>Could not determine the current environment.</error>");
            return;
        }

        try {
            $this->build($projectRoot, $environment['id']);
        } catch (\Exception $e) {
            $output->writeln("<error>" . $e->getMessage() . '</error>');
        }
    }

    /**
     * Build the project.
     *
     * @param string $projectRoot The path to the project to be built.
     * @param string $environmentId The environment id, used as a build suffix.
     */
    public function build($projectRoot, $environmentId)
    {
        chdir($projectRoot);
        $buildDir = 'builds/' . date('Y-m-d--H-i-s') . '--' . $environmentId;
        // @todo Implement logic for detecting a Drupal project VS others.
        $status = $this->buildDrupal($buildDir);
        if ($status) {
            // Point www to the latest build.
            if (file_exists('www')) {
                // @todo Windows might need rmdir instead of unlink.
                unlink('www');
            }
            symlink($buildDir, 'www');
        }
    }

    /**
     * Build a Drupal project in the provided directory.
     */
    protected function buildDrupal($buildDir)
    {
        $profiles = glob('repository/*.profile');
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the repository.");
        } elseif (count($profiles) == 1) {
            // Find the contrib make file.
            if (file_exists('repository/project.make')) {
                $projectMake = 'repository/project.make';
            } elseif (file_exists("repository/drupal-org.make")) {
                $projectMake = 'repository/drupal-org.make';
            } else {
                throw new \Exception("Couldn't find a project.make or drupal-org.make in the repository.");
            }
            // Find the core make file.
            if (file_exists('repository/project-core.make')) {
                $projectCoreMake = 'repository/project-core.make';
            } elseif (file_exists("repository/drupal-org-core.make")) {
                $projectCoreMake = 'repository/drupal-org-core.make';
            } else {
                throw new \Exception("Couldn't find a project-core.make or drupal-org-core.make in the repository.");
            }

            shell_exec("drush make -y $projectCoreMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
                $profile = str_replace('repository/', '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $buildDir . '/profiles/' . $profile;
                shell_exec("drush make -y --no-core --contrib-destination=. $projectMake $profileDir");
                $this->copy('repository', $profileDir);
            }
        } elseif (file_exists('repository/project.make')) {
            shell_exec("drush make -y repository/project.make $buildDir");
            $this->copy('repository', $buildDir . '/sites/default');
        }

        // Symlink all files and folders from shared.
        $this->symlink('shared', $buildDir . '/sites/default');

        return true;
    }

    /**
     * Copy all files and folders from $source into $destination.
     */
    protected function copy($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        $skip = array('.', '..', '.git');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                if (is_dir($source . '/' . $file)) {
                    $this->copy($source . '/' . $file, $destination . '/' . $file);
                } else {
                    copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Symlink all files and folders from $source into $destination.
     */
    protected function symlink($source, $destination)
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array('.', '..');
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                symlink($source . '/' . $file, $destination . '/' . $file);
            }
        }
        closedir($sourceDirectory);
    }
}
