<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
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
        $buildDir = $projectRoot . '/builds/' . date('Y-m-d--H-i-s') . '--' . $environmentId;
        // @todo Implement logic for detecting a Drupal project VS others.
        $stack= $this->detectStack($projectRoot . '/repository');
        switch ($stack) {
          case "symfony":
              $status = $this->buildSymfony($buildDir, $projectRoot);
              break;
            case "drupal":
            default:
              $status = $this->buildDrupal($buildDir, $projectRoot);
              break;
        }
        if ($status) {
            // Point www to the latest build.
            $wwwLink = $projectRoot . '/www';
            if (file_exists($wwwLink)) {
                // @todo Windows might need rmdir instead of unlink.
                unlink($wwwLink);
            }
            symlink($buildDir, $wwwLink);
        }else{
          throw new \Exception("Building $stack project failed");
        }
    }
    protected function detectStack($dir)
      {
        if (file_exists($dir. '/composer.json'))
        {
          $json=file_get_contents($dir. '/composer.json');
          $composer_json = json_decode($json);
          if (property_exists($composer_json->require,"symfony/symfony")) {
            return ("symfony");
          } else {
//            throw new \Exception("Couldn't find symfony in  composer.json in the repository.");
          };
      };
      if (recursive_file_exists("project.make", $dir)) {
        return ("drupal");
      }//|| (recursive_file_exists("drupal-org.make", $dir) ||(recursive_file_exists("drupal-org-core.make", $dir)

    }
    
    /**
     * Build a Symfony project in the provided directory.
     *
     * For a build to happen the repository must have at least one composer.json 
     *   into the /web directory.
     *
     * @param string $buildDir The path to the build directory.
     * @param string $projectRoot The path to the project to be built.
     */
    protected function buildSymfony($buildDir, $projectRoot)
    {
        $repositoryDir = $projectRoot . '/repository';
        if (file_exists($repositoryDir . '/composer.json')) {
            $projectComposer = $repositoryDir . '/composer.json';} else{
            throw new \Exception("Couldn't find a composer.json in the repository.");
        }
        mkdir($buildDir);
        $this->copy($repositoryDir, $buildDir);
        if (is_dir($buildDir)) {
            chdir($buildDir);
            shell_exec("composer install --no-progress --no-interaction  --working-dir $buildDir");
        }
        else {
          throw new \Exception("Couldn't create build directory");
        }
        // The build has been done, create a config_dev.yml if it is missing.
        if (is_dir($buildDir) && !file_exists($buildDir . '/app/config/config_dev.yml')) {
            // Create the config_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/config_dev.yml', $buildDir . '/app/config/config_dev.yml');
        }
        if (is_dir($buildDir) && !file_exists($buildDir . '/app/config/routing_dev.yml')) {
            // Create the routing_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/routing_dev.yml', $buildDir . '/app/config/routing_dev.yml');
        }        
        return true;
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

            shell_exec("drush make -y $projectCoreMake $buildDir");
            // Drush will only create the $buildDir if the build succeeds.
            if (is_dir($buildDir)) {
                $profile = str_replace($repositoryDir, '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $buildDir . '/profiles/' . $profile;
                symlink($repositoryDir, $profileDir);
                // Drush Make requires $profileDir to not exist if it's passed
                // as the target. chdir($profileDir) works around that.
                chdir($profileDir);
                shell_exec("drush make -y --no-core --contrib-destination=. $projectMake");
            }
        } elseif (file_exists($repositoryDir . '/project.make')) {
            $projectMake = $repositoryDir . '/project.make';
            shell_exec("drush make -y $projectMake $buildDir");
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
     * Delete a directory and all of its files.
     */
    protected function rmdir($directoryName)
    {
        if (is_dir($directoryName)) {
          // Recursively empty the directory.
          $directory = opendir($directoryName);
          while ($file = readdir($directory)) {
              if (!in_array($file, array('.', '..'))) {
                  if (is_dir($directoryName . '/' . $file)) {
                      $this->rmdir($directoryName . '/' . $file);
                  } else {
                      unlink($directoryName . '/' . $file);
                  }
              }
          }
          closedir($directory);

          // Delete the directory itself.
          rmdir($directoryName);
        }
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
    
    /*
     * @Search recursively for a file in a given directory
     *
     * @param string $filename The file to find
     *
     * @param string $directory The directory to search
     *
     * @return bool
     *
     */
    private function recursive_file_exists($filename, $directory)
    {
        try
        {
            /*** loop through the files in directory ***/
            foreach(new recursiveIteratorIterator( new recursiveDirectoryIterator($directory)) as $file)
            {
                /*** if the file is found ***/
                if( $directory.'/'.$filename == $file )
                {
                    return true;
                }
            }
            /*** if the file is not found ***/
            return false;
        }
        catch(Exception $e)
        {
            /*** if the directory does not exist or the directory
                or a sub directory does not have sufficent
                permissions return false ***/
            return false;
        }
    }
}
