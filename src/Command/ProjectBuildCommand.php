<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\LocalBuild;
use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectBuildCommand extends PlatformCommand
{
    /** @var OutputInterface */
    public $output;

    /** @var InputInterface */
    public $input;

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
                'wc',
                InputOption::VALUE_NONE,
                'Drush: use git to clone a repository of each Drupal module rather than simply downloading a version.'
            )->addOption(
                'concurrency',
                null,
                InputOption::VALUE_OPTIONAL,
                'Drush: set the number of concurrent projects that will be processed at the same time.',
                3
            );
        }
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

        $settings = array();

        // The environment ID is used in making the build directory name.
        $settings['environmentId'] = $envId;

        $settings['absoluteLinks'] = $this->input->getOption('abslinks');
        $settings['verbosity'] = $this->output->getVerbosity();
        $settings['drushConcurrency'] = $this->input->getOption('concurrency');
        $settings['drushWorkingCopy'] = $this->input->getOption('working-copy');

        try {
            $this->build($projectRoot, $settings);
        } catch (\Exception $e) {
            $output->writeln("<error>" . $e->getMessage() . '</error>');
        }
    }

    /**
     * Build the project.
     *
     * @param string $projectRoot The path to the project to be built.
     * @param array $settings
     *
     * @throws \Exception
     */
    public function build($projectRoot, array $settings)
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
                throw new \Exception("Could not detect toolstack for directory: " . $appRoot);
            }

            $message = "Building application";
            if ($appName) {
                $message .= " <info>$appName</info>";
            }
            $message .= " using the toolstack <info>" . $toolstack->getKey() . "</info>";
            $this->output->writeln($message);

            $toolstack->prepareBuild($appRoot, $projectRoot, $settings);

            $toolstack->build();
            $toolstack->install();

            $message = "Build complete";
            if ($appName) {
                $message .= " for <info>$appName</info>";
            }
            $this->output->writeln($message);
        }

    }
}
