<?php
namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildCommand extends PlatformCommand
{

    protected $defaultDrushConcurrency = 4;

    protected function configure()
    {
        $this
          ->setName('local:build')
          ->setAliases(array('build'))
          ->addArgument('app', InputArgument::IS_ARRAY, 'Specify application(s) to build')
          ->setDescription('Build the current project locally')
          ->addOption(
            'abslinks',
            'a',
            InputOption::VALUE_NONE,
            'Use absolute links'
          )
          ->addOption(
            'source',
            null,
            InputOption::VALUE_OPTIONAL,
            'The source directory. Default: ' . LocalProject::REPOSITORY_DIR
          )
          ->addOption(
            'destination',
            null,
            InputOption::VALUE_OPTIONAL,
            'The destination, to which the web root of each app will be symlinked. Default: ' . LocalProject::WEB_ROOT
          )
          ->addOption(
            'copy',
            null,
            InputOption::VALUE_NONE,
            'Copy to a build directory, instead of symlinking from the source'
          )
          ->addOption(
            'no-clean',
            null,
            InputOption::VALUE_NONE,
            'Do not remove old builds'
          )
          ->addOption(
            'no-archive',
            null,
            InputOption::VALUE_NONE,
            'Do not create or use a build archive'
          )
          ->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'Disable caching'
          )
          ->addOption(
            'no-build-hooks',
            null,
            InputOption::VALUE_NONE,
            'Do not run post-build hooks'
          )
          ->addOption(
            'working-copy',
            null,
            InputOption::VALUE_NONE,
            'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version'
          )
          ->addOption(
            'concurrency',
            null,
            InputOption::VALUE_OPTIONAL,
            'Drush: set the number of concurrent projects that will be processed at the same time',
            $this->defaultDrushConcurrency
          )
          ->addOption(
            'lock',
            null,
            InputOption::VALUE_NONE,
            'Drush: create or update a lock file (only available with Drush version 7+)'
          );
        $this->addExample('Build the current project');
        $this->addExample('Build the app "example" without symlinking the source files', 'example --copy');
        $this->addExample('Rebuild the current project without using an archive', '--no-archive');
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $sourceDirOption = $input->getOption('source');

        // If no project root is found, ask the user for a source directory.
        if (!$projectRoot && !$sourceDirOption && $input->isInteractive()) {
            $sourceDirOption = $questionHelper->askInput('Source directory', $input, $this->stdErr);
        }

        if ($sourceDirOption) {
            $sourceDir = realpath($sourceDirOption);
            if (!is_dir($sourceDir)) {
                throw new \InvalidArgumentException('Source directory not found: ' . $sourceDirOption);
            }
            // Sensible handling if the user provides a project root as the
            // source directory.
            elseif (file_exists($sourceDir . '/.platform-project')) {
                $projectRoot = $sourceDir;
                $sourceDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
            }
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --source or go to a project directory.');
        }
        else {
            $sourceDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        }

        $destination = $input->getOption('destination');

        // If no project root is found, ask the user for a destination path.
        if (!$projectRoot && !$destination && $input->isInteractive()) {
            $destination = $questionHelper->askInput('Build destination', $input, $this->stdErr);
        }

        if ($destination) {
            /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
            $fsHelper = $this->getHelper('fs');
            $destination = $fsHelper->makePathAbsolute($destination);
        }
        elseif (!$projectRoot) {
            throw new RootNotFoundException('Project root not found. Specify --destination or go to a project directory.');
        }
        else {
            $destination = $projectRoot . '/' . LocalProject::WEB_ROOT;
        }

        // Ensure no conflicts between source and destination.
        if (strpos($sourceDir, $destination) === 0) {
            throw new \InvalidArgumentException("The destination '$destination' conflicts with the source '$sourceDir'");
        }

        // Ask the user about overwriting the destination, if a project root was
        // not found.
        if (!$projectRoot && file_exists($destination)) {
            if (!is_writable($destination)) {
                $this->stdErr->writeln("The destination exists and is not writable: <error>$destination</error>");
                return 1;
            }
            $default = is_link($destination);
            if (!$questionHelper->confirm("The destination exists: <comment>$destination</comment>. Overwrite?", $input, $this->stdErr, $default)) {
                return 1;
            }
        }

        $settings = array();

        $settings['projectRoot'] = $projectRoot;

        $settings['environmentId'] = $this->determineEnvironmentId($sourceDir, $projectRoot);

        $settings['verbosity'] = $output->getVerbosity();

        $settings['drushConcurrency'] = $input->hasOption('concurrency') ? $input->getOption('concurrency') : $this->defaultDrushConcurrency;

        // Some simple settings flags.
        $settingsMap = array(
          'absoluteLinks' => 'abslinks',
          'copy' => 'copy',
          'drushWorkingCopy' => 'working-copy',
          'drushUpdateLock' => 'lock',
          'noArchive' => 'no-archive',
          'noCache' => 'no-cache',
          'noClean' => 'no-clean',
          'noBuildHooks' => 'no-build-hooks',
        );
        foreach ($settingsMap as $setting => $option) {
            $settings[$setting] = $input->hasOption($option) && $input->getOption($option);
        }

        $apps = $input->getArgument('app');

        $builder = new LocalBuild($settings, $this->stdErr);
        $success = $builder->build($sourceDir, $destination, $apps);

        return $success ? 0 : 1;
    }

    /**
     * Find out the environment ID, if possible.
     *
     * This is appended to the build directory name.
     *
     * @param string $sourceDir
     * @param string|false $projectRoot
     *
     * @return string|false
     */
    protected function determineEnvironmentId($sourceDir, $projectRoot = null)
    {
        // Find out the real environment ID, if possible.
        if ($projectRoot && $this->isLoggedIn()) {
            try {
                $project = $this->getCurrentProject();
            }
            catch (\Exception $e) {
                // An exception may be thrown if the user no longer has access
                // to the project, or perhaps if there is no network access. We
                // can still let the user build the project locally.
                $project = false;
            }
            if ($project && ($environment = $this->getCurrentEnvironment($project))) {
                return $environment['id'];
            }
        }

        // Fall back to the Git branch name.
        if (is_dir($sourceDir . '/.git')) {
            /** @var \Platformsh\Cli\Helper\GitHelper $gitHelper */
            $gitHelper = $this->getHelper('git');
            return $gitHelper->getCurrentBranch($sourceDir);
        }

        return false;
    }
}
