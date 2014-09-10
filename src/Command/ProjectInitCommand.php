<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ProjectInitCommand extends PlatformCommand
{
    private $absoluteLinks;
    protected $wcOption = FALSE;

    protected function configure()
    {
        $this
            ->setName('project:init')
            ->setAliases(array('init'))
            ->setDescription('Inits platform for an existing git repo or creates a new one.')
              ->addArgument(
                  'stack',
                  InputArgument::OPTIONAL,
                  'The name of the stack'
              )
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links.'
            )
            ->addOption('working-copy', 'wc', InputOption::VALUE_NONE, 'Use git to clone a repository of each Drupal module rather than simply downloading a version.');
        $this->ignoreValidationErrors();
        parent::configure();
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
            $this->init($projectRoot, $envId);
        } catch (\Exception $e) {
            $output->writeln("<error>" . $e->getMessage() . '</error>');
        }
    }

    /**
     * Init the project.
     *
     * @param string $projectRoot The path to the project to be built.
     * @param string $environmentId The environment id, used as a init suffix.
     */
    public function init($projectRoot, $environmentId)
    {
        $initName = date('Y-m-d--H-i-s') . '--' . $environmentId;
        $relInitDir = 'inits/' . $initName;
        $absInitDir = $projectRoot . '/' . $relInitDir;
        // Implement logic for detecting a Drupal project VS others.
        $stack= $this->detectStack($projectRoot . '/repository');
        switch ($stack) {
          case "symfony":
              $status = $this->initSymfony($absInitDir, $projectRoot);
              break;
            case "drupal":
            default:
              $status = $this->initDrupal($absInitDir, $projectRoot);
              break;
        }
        if ($status) {
            // Point www to the latest init.
            $wwwLink = $projectRoot . '/www';
            if (file_exists($wwwLink) || is_link($wwwLink)) {
                // @todo Windows might need rmdir instead of unlink.
                unlink($wwwLink);
            }
            symlink($this->absoluteLinks ? $absInitDir : $relInitDir, $wwwLink);
        }else{
          throw new \Exception("Initing $stack project failed");

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
     * Init a Symfony project in the provided directory.
     *
     * For a init to happen the repository must have at least one composer.json 
     *   into the /web directory.
     *
     * @param string $initDir The path to the init directory.
     * @param string $projectRoot The path to the project to be built.
     */
    protected function initSymfony($initDir, $projectRoot)
    {
        $repositoryDir = $projectRoot . '/repository';
        if (file_exists($repositoryDir . '/composer.json')) {
            $projectComposer = $repositoryDir . '/composer.json';} else{
            throw new \Exception("Couldn't find a composer.json in the repository.");
        }
        mkdir($initDir);
        $this->copy($repositoryDir, $initDir);
        if (is_dir($initDir)) {
            chdir($initDir);
            shell_exec("composer install --no-progress --no-interaction  --working-dir $initDir");
        }
        else {
          throw new \Exception("Couldn't create init directory");
        }
        // The init has been done, create a config_dev.yml if it is missing.
        if (is_dir($initDir) && !file_exists($initDir . '/app/config/config_dev.yml')) {
            // Create the config_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/config_dev.yml', $initDir . '/app/config/config_dev.yml');
        }
        if (is_dir($initDir) && !file_exists($initDir . '/app/config/routing_dev.yml')) {
            // Create the routing_dev.yml file.
            copy(CLI_ROOT . '/resources/symfony/routing_dev.yml', $initDir . '/app/config/routing_dev.yml');
        }        
        return true;
    }
    
    /**
     * Init a Drupal project in the provided directory.
     *
     * For a init to happen the repository must have at least one drush make
     * file. There are two possible modes:
     * - installation profile: Contains an installation profile and the matching
     *   drush make files (project.make and project-core.make or drupal-org.make
     *   and drupal-org-core.make). The repository is symlinked into the
     *   profile directory (profiles/$profileName).
     * - site: Contains just a project.make file. The repository is symlinked
     *   into the sites/default directory.
     *
     * @param string $initDir The path to the init directory.
     * @param string $projectRoot The path to the project to be built.
     */
    protected function initDrupal($initDir, $projectRoot)
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

            shell_exec("drush make -y $wcOption $projectCoreMake $initDir");
            // Drush will only create the $initDir if the init succeeds.
            if (is_dir($initDir)) {
                $profile = str_replace($repositoryDir, '', $profiles[0]);
                $profile = strtok($profile, '.');
                $profileDir = $initDir . '/profiles/' . $profile;
                symlink($repositoryDir, $profileDir);
                // Drush Make requires $profileDir to not exist if it's passed
                // as the target. chdir($profileDir) works around that.
                chdir($profileDir);
                shell_exec("drush make -y $wcOption --no-core --contrib-destination=. $projectMake");
            }
        } elseif (file_exists($repositoryDir . '/project.make')) {
            $projectMake = $repositoryDir . '/project.make';
            shell_exec("drush make -y $wcOption $projectMake $initDir");
            // Drush will only create the $initDir if the init succeeds.
            if (is_dir($initDir)) {
              // Remove sites/default to make room for the symlink.
              $this->rmdir($initDir . '/sites/default');
              $this->symlink($repositoryDir, $initDir . '/sites/default');
            }
        }
        else {
            // Nothing to init.
            return;
        }

        // The init has been done, create a settings.php if it is missing.
        if (is_dir($initDir) && !file_exists($initDir . '/sites/default/settings.php')) {
            // Create the settings.php file.
            copy(CLI_ROOT . '/resources/drupal/settings.php', $initDir . '/sites/default/settings.php');
        }

        // Symlink all files and folders from shared.
        $this->symlink($projectRoot . '/shared', $initDir . '/sites/default');

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
    
    /*
     * @Search recursively for a file in a given directory
     * @param string $filename The file to find
     * @param string $directory The directory to search
     * @return bool
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
