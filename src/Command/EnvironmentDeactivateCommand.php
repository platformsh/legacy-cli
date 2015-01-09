<?php

namespace CommerceGuys\Platform\Cli\Command;

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
          ->addOption('merged', null, InputOption::VALUE_NONE, 'Deactivate all merged environments');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environments = $this->environment ? array($this->environment) : $input->getArgument('environment');

        if ($input->getOption('merged')) {
            $parent = reset($environments);
            $output->writeln("Finding environments merged with <info>$parent</info>");
            $environments = $this->getMergedEnvironments($output);
            if (!$environments) {
                $output->writeln("No merged environments found");
                return 0;
            }
        }

        $success = $this->deactivateMultiple($environments, $input, $output);

        return $success ? 0 : 1;
    }

    protected function getMergedEnvironments()
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \RuntimeException("This can only be run from inside a project directory");
        }
        $environments = $this->getEnvironments($this->project, true);
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot . '/repository');
        $gitHelper->execute(array('fetch', 'origin'));
        $mergedBranches = $gitHelper->getMergedBranches($this->environment['id']);
        $mergedEnvironments = array_intersect_key($environments, array_flip($mergedBranches));
        unset($mergedEnvironments[$this->environment['id']], $mergedEnvironments['master']);
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
        $deactivated = 0;
        // Confirm which environments the user wishes to be deactivated.
        $deactivate = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            if (!is_array($environment)) {
                $environment = $this->getEnvironment($environment, $this->project);
                if (!$environment) {
                    continue;
                }
            }
            $environmentId = $environment['id'];
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deactivated or deleted.");
                continue;
            }
            if (empty($environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already inactive.");
                $deactivated++;
                continue;
            }
            if (!$this->operationAllowed('deactivate', $environment)) {
                $output->writeln("Operation not permitted: The environment <error>$environmentId</error> can't be deactivated.");
                continue;
            }
            $question = "Are you sure you want to deactivate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $deactivate[$environmentId] = $environment;
        }
        foreach ($deactivate as $environmentId =>  $environment) {
            $client = $this->getPlatformClient($environment['endpoint']);
            try {
                $client->deactivateEnvironment();
                $deactivated++;
                $output->writeln("Deactivated environment <info>$environmentId</info>");
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        if ($deactivated) {
            $this->getEnvironments($this->project, true);
        }
        return $deactivated >= $count;
    }

}
