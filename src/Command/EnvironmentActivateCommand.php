<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Activity;
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
        $this->addProjectOption()
          ->addEnvironmentOption()
          ->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        if ($this->environment) {
            $toActivate = array($this->environment);
        }
        else {
            $environments = $this->getEnvironments($this->project);
            $environmentIds = $input->getArgument('environment');
            $toActivate = array_intersect_key($environments, array_flip($environmentIds));
            $notFound = array_diff($environmentIds, array_keys($environments));
            foreach ($notFound as $notFoundId) {
                $output->writeln("Environment not found: <error>$notFoundId</error>");
            }
        }

        $success = $this->activateMultiple($toActivate, $input, $output);

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
            $environmentId = $environment['id'];
            if (!empty($environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                $count--;
                continue;
            }
            if (!$this->operationAvailable('activate', $environment)) {
                $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be activated.");
                continue;
            }
            $question = "Are you sure you want to activate the environment <info>$environmentId</info>?";
            if (!$questionHelper->confirm($question, $input, $output)) {
                continue;
            }
            $process[$environmentId] = $environment;
        }
        $responses = array();
        foreach ($process as $environmentId =>  $environment) {
            $client = $this->getPlatformClient($environment['endpoint']);
            try {
                $output->writeln("Activating environment <info>$environmentId</info>");
                $responses[] = $client->activateEnvironment();
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
