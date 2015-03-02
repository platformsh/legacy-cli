<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
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
                   8
                 )
                 ->addOption(
                   'no-cache',
                   null,
                   InputOption::VALUE_NONE,
                   'Drush: disable pm-download caching.'
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
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");

            return 1;
        }
        if ($this->isLoggedIn()) {
            $project = $this->getCurrentProject();
            if (!$project) {
                throw new \RuntimeException("Could not determine the current project");
            }
            $environment = $this->getCurrentEnvironment($project);
            if (!$environment) {
                throw new \RuntimeException("Could not determine the current environment");
            }
            $envId = $environment['id'];
        } else {
            // Login was skipped so we figure out the environment ID from git.
            $head = file($projectRoot . '/' . LocalProject::REPOSITORY_DIR . '/.git/HEAD');
            $branchRef = $head[0];
            $branch = trim(substr($branchRef, 16));
            $envId = $branch;
        }

        $apps = $input->getArgument('app');

        $settings = array();

        // The environment ID is used in making the build directory name.
        $settings['environmentId'] = $envId;

        $settings['verbosity'] = $output->getVerbosity();

        $settings['drushConcurrency'] = $input->hasOption('concurrency') ? $input->getOption('concurrency') : 3;

        // Some simple settings flags.
        $settingsMap = array(
          'absoluteLinks' => 'abslinks',
          'drushWorkingCopy' => 'working-copy',
          'noArchive' => 'no-archive',
          'noCache' => 'no-cache',
          'noClean' => 'no-clean',
        );
        foreach ($settingsMap as $setting => $option) {
            $settings[$setting] = $input->hasOption($option) && $input->getOption($option);
        }

        try {
            $builder = new LocalBuild($settings, $output);
            $success = $builder->buildProject($projectRoot, $apps);
        } catch (\Exception $e) {
            $output->writeln("<error>The build failed with an error</error>");
            $formattedMessage = $this->getHelper('formatter')
                                     ->formatBlock($e->getMessage(), 'error');
            $output->writeln($formattedMessage);

            return 1;
        }

        return $success ? 0 : 2;
    }

}
