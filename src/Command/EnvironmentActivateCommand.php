<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to activate');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environments = $this->environment ? array($this->environment) : $input->getArgument('environment');

        $success = $this->activateMultiple($environments, $input, $output);

        return $success ? 0 : 1;
    }

    /**
     * @param array           $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function activateMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        $count = count($environments);
        $processed = 0;
        // Confirm which environments the user wishes to be deactivated.
        $process = array();
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            if (!is_array($environment)) {
                $requested = $environment;
                $environment = $this->getEnvironment($environment, $this->project);
                if (!$environment) {
                    $output->writeln("Environment not found: <error>$requested</error>");
                    continue;
                }
            }
            $environmentId = $environment['id'];
            if (!empty($environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                $processed++;
                continue;
            }
            if (!$this->operationAllowed('activate', $environment)) {
                $output->writeln("Operation not permitted: The environment <error>$environmentId</error> can't be activated.");
                continue;
            }
            $question = "Are you sure you want to activate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        foreach ($process as $environmentId =>  $environment) {
            $client = $this->getPlatformClient($environment['endpoint']);
            try {
                $client->activateEnvironment();
                $processed++;
                $output->writeln("Activated environment <info>$environmentId</info>");
            }
            catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
        if ($processed) {
            $this->getEnvironments($this->project, true);
        }
        return $processed >= $count;
    }

}
