<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentBuildCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:build')
            ->setAliases(array('build'))
            ->setDescription('Builds an environment.')
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return;
        }
        if (!$this->validateInput($input, $output)) {
            return;
        }

        try {
            $this->build($projectRoot);
        }
        catch (\Exception $e) {
            $output->writeln("<error>" . $e->getMessage() . '</error>');
        }
    }

    public function build($projectRoot)
    {
        chdir($projectRoot);
        $buildDir = 'builds/' . date('Y-m-d--H-i-s') . '--' . $this->environment['id'];
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

    protected function buildDrupal($buildDir)
    {
        $profiles = glob('repository/*.profile');
        if (count($profiles) > 1) {
            throw new \Exception("Found multiple files ending in '*.profile' in the repository.");
        }
        elseif (count($profiles) == 1) {
            // Find the contrib make file.
            if (file_exists('repository/project.make')) {
                $projectMake = 'repository/project.make';
            }
            elseif (file_exists("repository/drupal-org.make")) {
                $projectMake = 'repository/drupal-org.make';
            }
            else {
                throw new \Exception("Couldn't find a project.make or drupal-org.make in the repository.");
            }
            // Find the core make file.
            if (file_exists('repository/project-core.make')) {
                $projectCoreMake = 'repository/project-core.make';
            }
            elseif (file_exists("repository/drupal-org-core.make")) {
                $projectCoreMake = 'repository/drupal-org-core.make';
            }
            else {
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
        }
        elseif (file_exists('repository/project.make')) {
            shell_exec("drush make -y repository/project.make $buildDir");
            $this->copy('repository', $buildDir . '/sites/default');
        }

        // Symlink all files and folders from shared.
        $this->symlink('shared', $buildDir . '/sites/default');

        return true;
    }

    /**
     * Copies all files and folders from $source into $destination.
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
                }
                else {
                    copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Symlinks all files and folders from $source into $destination.
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
