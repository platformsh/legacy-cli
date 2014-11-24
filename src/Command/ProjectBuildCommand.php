<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\LocalBuild;
use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
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
            ->setDescription('Builds the current project.')
            ->addOption(
                'abslinks',
                'a',
                InputOption::VALUE_NONE,
                'Use absolute links.'
            );
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || Drupal::isDrupal($projectRoot . '/repository')) {
            $this->addOption(
                'working-copy',
                null,
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version.'
            )->addOption(
                'concurrency',
                null,
                InputOption::VALUE_OPTIONAL,
                'Drush: set the number of concurrent projects that will be processed at the same time.',
                3
            )->addOption(
              'no-cache',
              null,
              InputOption::VALUE_NONE,
              'Drush: disable pm-download caching.'
            );
        }
    }

    public function isLocal()
    {
        return TRUE;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        if (empty($projectRoot)) {
            $output->writeln("<error>You must run this command from a project folder.</error>");
            return 1;
        }
        if ($this->config) {
            $project = $this->getCurrentProject();
            if (!$project) {
                throw new \RuntimeException("Could not determine the current project");
            }
            $environment = $this->getCurrentEnvironment($project);
            if (!$environment) {
                throw new \RuntimeException("Could not determine the current environment");
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

        $settings = array();

        // The environment ID is used in making the build directory name.
        $settings['environmentId'] = $envId;

        $settings['verbosity'] = $output->getVerbosity();

        $settings['drushConcurrency'] = $input->hasOption('concurrency') ? $input->getOption('concurrency') : 3;

        // Some simple settings flags.
        $settingsMap = array(
          'absoluteLinks' => 'abslinks',
          'drushWorkingCopy' => 'working-copy',
          'noCache' => 'no-cache',
        );
        foreach ($settingsMap as $setting => $option) {
            $settings[$setting] = $input->hasOption($option) && $input->getOption($option);
        }

        try {
            $this->build($projectRoot, $settings, $output);
        } catch (\Exception $e) {
            $output->writeln("<error>The build failed with an error</error>");
            $formattedMessage = $this->getHelper('formatter')->formatBlock($e->getMessage(), 'error');
            $output->writeln($formattedMessage);
            return 1;
        }

        return 0;
    }

    /**
     * Build the project.
     *
     * @param string $projectRoot The path to the project to be built.
     * @param array $settings
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    public function build($projectRoot, array $settings, OutputInterface $output)
    {
        $repositoryRoot = $projectRoot . '/repository';

        foreach (LocalBuild::getApplications($repositoryRoot) as $appRoot) {
            $appConfig = LocalBuild::getAppConfig($appRoot);
            $appName = false;
            if ($appConfig && isset($appConfig['name'])) {
                $appName = $appConfig['name'];
            }
            elseif ($appRoot != $repositoryRoot) {
                $appName = str_replace($repositoryRoot, '', $appRoot);
            }

            $toolstack = LocalBuild::getToolstack($appRoot, $appConfig);
            if (!$toolstack) {
                $output->writeln("<comment>Could not detect toolstack for directory: $appRoot</comment>");
                continue;
            }

            $message = "Building application";
            if ($appName) {
                $message .= " <info>$appName</info>";
            }
            $message .= " using the toolstack <info>" . $toolstack->getKey() . "</info>";
            $output->writeln($message);

            $toolstack->setOutput($output);
            $toolstack->prepareBuild($appRoot, $projectRoot, $settings);

            $toolstack->build();
            $toolstack->install();

            $message = "Build complete";
            if ($appName) {
                $message .= " for <info>$appName</info>";
            }
            $output->writeln($message);
        }

    }
}
