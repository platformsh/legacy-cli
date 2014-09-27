<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use CommerceGuys\Platform\Cli\Toolstack;

class ProjectBuildCommand extends PlatformCommand
{
    public $absoluteLinks;

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
            ->addOption(
                'working-copy',
                'wc',
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version.'
            )
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_OPTIONAL,
                'Drush: set the number of concurrent projects that will be processed at the same time. The default is 3.',
                3
            );
    }

    public function isLocal()
    {
      return TRUE;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

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
        // New build scaffolding. 
        // @todo: Determine multiple project roots and build each one.
        
        
        // @todo: Parse .platform.app.yaml file into $settings array for detection.
        
        
        // @temp: Current project root and empty settings:
        $applications[] = array('appRoot' => $projectRoot . "/repository", 'settings' => array('environmentId' => $environmentId, 'projectRoot' => $this->getProjectRoot()));
        foreach ($applications as $app) {
            // Detect the toolstack.
            foreach ($this->getApplication()->getToolstacks() as $toolstack) {
                $classname = "\\CommerceGuys\\Platform\\Cli\\Toolstack\\{$toolstack}App";
                if ($classname::detect($app['appRoot'], $app['settings'])) {
                    $this->toolstackClassName = $classname;
                }
            }
            if (isset($this->toolstackClassName)) {
                $toolstack = new $this->toolstackClassName($this, $app['settings']);
                $this->toolstack = $toolstack;
            }
            else {
                // Failed to find a toolstack. @todo dump an error here.
                break;
            }
            
            $this->toolstack->prepareBuild();
            $this->toolstack->build();
        }
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
