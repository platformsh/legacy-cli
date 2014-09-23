<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectBuildCommand extends PlatformCommand
{
    private $absoluteLinks;
    protected $wcOption = FALSE;

    protected function configure()
    {
        $this
            ->setName('project:build')
            ->setAliases(array('build'))
            ->setDescription('Builds the current project.')
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links.'
            )
            ->addOption('working-copy', 'wc', InputOption::VALUE_NONE, 'Use git to clone a repository of each Drupal module rather than simply downloading a version.');
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->wcOption = $input->getOption('working-copy');

        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return;
        }
        if ($this->config) {
            $project = $this->getCurrentProject();
            $environment = $this->getCurrentEnvironment($project);
                if (!$environment) {
                    $output->writeln("<error>Could not determine the current environment.</error>");
                    return;
                }
            $envId = $environment['id'];
        }
        else {
            // Login was skipped so we figure out the environment ID from git.
            $head = file($projectRoot . '/repository/.git/HEAD');
            $branchRef = $head[0];
            $branch = trim(substr($branchRef,16));
            $envId = $branch;
        }
        $this->absoluteLinks = $input->getOption('abslinks');

        try {
            $this->build($projectRoot, $envId);
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
        $buildName = date('Y-m-d--H-i-s') . '--' . $environmentId;
        $relBuildDir = 'builds/' . $buildName;
        $absBuildDir = $projectRoot . '/' . $relBuildDir;
        // @todo Implement logic for detecting a Drupal project VS others.
        $status = $this->buildDrupal($absBuildDir, $projectRoot);
        if ($status) {
            // Point www to the latest build.
            $wwwLink = $projectRoot . '/www';
            if (file_exists($wwwLink) || is_link($wwwLink)) {
                // @todo Windows might need rmdir instead of unlink.
                unlink($wwwLink);
            }
            symlink($this->absoluteLinks ? $absBuildDir : $relBuildDir, $wwwLink);
        }
    }

    /**
     * Build a Drupal project in the provided directory.
     *
     * For a build to happen the repository must have at least one drush make
     * file. There are two possible modes:
     * - installation profile: Contains an installation profile and the matching
     *   drush make files (project.make and project-core.make or drupal-org.make
     *   and drupal-org-core.make). The repository is symlinked into the
     *   profile directory (profiles/$profileName).
     * - site: Contains just a project.make file. The repository is symlinked
     *   into the sites/default directory.
     *
     * @param string $buildDir The path to the build directory.
     * @param string $projectRoot The path to the project to be built.
     */
    protected function buildDrupal($buildDir, $projectRoot)
    {
        $this->ensureDrushInstalled();

        $wcOption = ($this->wcOption ? "--working-copy" : "");

        $repositoryDir = $projectRoot . '/repository';
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

            shell_exec("drush make -y $wcOption $projectCoreMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
                $profile = str_replace($repositoryDir, '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $buildDir . '/profiles/' . $profile;
                symlink($repositoryDir, $profileDir);
                // Drush Make requires $profileDir to not exist if it's passed
                // as the target. chdir($profileDir) works around that.
                chdir($profileDir);
                shell_exec("drush make -y $wcOption --no-core --contrib-destination=. $projectMake");
            }
        } elseif (file_exists($repositoryDir . '/project.make')) {
            $projectMake = $repositoryDir . '/project.make';
            shell_exec("drush make -y $wcOption $projectMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
              // Remove sites/default to make room for the symlink.
              $this->rmdir($buildDir . '/sites/default');
              $this->symlink($repositoryDir, $buildDir . '/sites/default', array(
                  '.git',
                  '.platform',
                  '.platform.app.yaml',
              ));
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
        $this->symlink($projectRoot . '/shared', $buildDir . '/sites/default');

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
    protected function symlink($source, $destination, array $skip = array())
    {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);
        $skip = array_merge($skip, array('.', '..'));
        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            if (!in_array($file, $skip)) {
                $sourceFile = $source . '/' . $file;
                $linkFile = $destination . '/' . $file;

                if (!$this->absoluteLinks) {
                    $sourceFile = $this->makePathRelative($sourceFile, $linkFile);
                }
                symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    public static function skipLogin()
    {
        return TRUE;
    }

    /**
     * Make relative path between two files.
     *
     * @param string $source Path of the file we are linking to.
     * @param string $destination Path to the symlink.
     * @return string Relative path to the source, or file linking to.
     */
    private function makePathRelative($source, $dest)
    {
        $i = 0;
        while (true) {
            if(substr($source, $i, 1) != substr($dest, $i, 1)) {
                break;
            }
            $i++;
        }
        $distance = substr_count(substr($dest, $i - 1, strlen($dest)), '/') - 1;

        $path = '';
        while ($distance) {
            $path .= '../';
            $distance--;
        }
        $path .= substr($source, $i, strlen($source));

        return $path;
    }
}
