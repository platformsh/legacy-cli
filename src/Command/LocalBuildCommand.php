<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildCommand extends PlatformCommand
{

    protected $defaultDrushConcurrency = 1;

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
            'Disable caching.'
          )
          ->addOption(
            'no-build-hooks',
            null,
            InputOption::VALUE_NONE,
            'Do not run post-build hooks.'
          );
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            $this->addOption(
              'working-copy',
              null,
              InputOption::VALUE_NONE,
              'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version.'
            )
            ->addOption(
              'concurrency',
              null,
              InputOption::VALUE_OPTIONAL,
              'Drush: set the number of concurrent projects that will be processed at the same time.',
              $this->defaultDrushConcurrency
            );
        }
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        // Find out the real environment ID, if possible.
        if ($this->isLoggedIn()) {
            $project = $this->getCurrentProject();
            if ($project) {
                $environment = $this->getCurrentEnvironment($project);
                if ($environment) {
                    $envId = $environment['id'];
                }
            }
        }

        // Otherwise, use the Git branch name.
        if (!isset($envId)) {
            $gitHelper = $this->getHelper('git');
            $envId = $gitHelper->getCurrentBranch($projectRoot . '/' . LocalProject::REPOSITORY_DIR, true);
        }

        $apps = $input->getArgument('app');

        $settings = array();

        // The environment ID is used in making the build directory name.
        $settings['environmentId'] = $envId;

        $settings['verbosity'] = $output->getVerbosity();

        $settings['drushConcurrency'] = $input->hasOption('concurrency') ? $input->getOption('concurrency') : $this->defaultDrushConcurrency;

        // Some simple settings flags.
        $settingsMap = array(
          'absoluteLinks' => 'abslinks',
          'drushWorkingCopy' => 'working-copy',
          'noArchive' => 'no-archive',
          'noCache' => 'no-cache',
          'noClean' => 'no-clean',
          'noBuildHooks' => 'no-build-hooks',
        );
        foreach ($settingsMap as $setting => $option) {
            $settings[$setting] = $input->hasOption($option) && $input->getOption($option);
        }

        try {
            $builder = new LocalBuild($settings, $this->stdErr);
            $success = $builder->buildProject($projectRoot, $apps);
        } catch (\Exception $e) {
            $this->stdErr->writeln("<error>The build failed with an error</error>");
            $formattedMessage = $this->getHelper('formatter')
                                     ->formatBlock($e->getMessage(), 'error');
            $this->stdErr->writeln($formattedMessage);

            return 1;
        }

        return $success ? 0 : 2;
    }

}
