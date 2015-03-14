<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\LocalProject;
use CommerceGuys\Platform\Cli\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeactivateCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:deactivate')
          ->setDescription('Deactivate an environment')
          ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to deactivate')
          ->addOption('merged', null, InputOption::VALUE_NONE, 'Deactivate all merged environments')
          ->addOption(
            'no-wait',
            null,
            InputOption::VALUE_NONE,
            "Do not wait for the operation to complete"
          );
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        if ($input->getOption('merged')) {
            if (!$this->environment) {
                $output->writeln("No base environment specified");
                return 1;
            }
            $base = $this->environment['id'];
            $output->writeln("Finding environments merged with <info>$base</info>");
            $toDeactivate = $this->getMergedEnvironments($base);
            if (!$toDeactivate) {
                $output->writeln("No merged environments found");
                return 0;
            }
        }
        elseif ($this->environment) {
            $toDeactivate = array($this->environment);
        }
        else {
            $environments = $this->getEnvironments($this->project);
            $environmentIds = $input->getArgument('environment');
            $toDeactivate = array_intersect_key($environments, array_flip($environmentIds));

            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $output->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->deactivateMultiple($toDeactivate, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param string $base
     *
     * @return array
     */
    protected function getMergedEnvironments($base)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \RuntimeException("This can only be run from inside a project directory");
        }
        $environments = $this->getEnvironments($this->project, true);
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        $gitHelper->execute(array('fetch', 'origin'));
        $mergedBranches = $gitHelper->getMergedBranches($base);
        $mergedEnvironments = array_intersect_key($environments, array_flip($mergedBranches));
        unset($mergedEnvironments[$base], $mergedEnvironments['master']);
        $parent = $environments[$base]['parent'];
        if ($parent) {
            unset($mergedEnvironments[$parent]);
        }
        return $mergedEnvironments;
    }

    /**
     * @param array           $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function deactivateMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be deactivated.
        $process = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment['id'];
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deactivated or deleted.");
                continue;
            }
            if (empty($environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already inactive.");
                $count--;
                continue;
            }
            if (!$this->operationAvailable('deactivate', $environment)) {
                $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be deactivated.");
                continue;
            }
            $question = "Are you sure you want to deactivate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        $responses = array();
        foreach ($process as $environmentId =>  $environment) {
            $client = $this->getPlatformClient($environment['endpoint']);
            try {
                $output->writeln("Deactivating environment <info>$environmentId</info>");
                $responses[] = $client->deactivateEnvironment();
                $processed++;
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        if (isset($client) && !$input->getOption('no-wait')) {
            Activity::waitMultiple($responses, $client, $output);
        }
        if ($processed) {
            $this->getEnvironments($this->project, true);
        }
        return $processed >= $count;
    }

}
