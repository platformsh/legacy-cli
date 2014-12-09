<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;

use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeactivateCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:deactivate')
            ->setDescription('Deactivate an environment');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];
        if ($environmentId == 'master') {
            $output->writeln("<error>The master environment cannot be deactivated or deleted.</error>");
            return 1;
        }

        if (!$this->operationAllowed('deactivate')) {
            if ($this->environment['status'] == 'inactive') {
                $output->writeln("The environment <info>$environmentId</info> is already inactive.");
                return 0;
            }
            $output->writeln("<error>Operation not permitted: The environment '$environmentId' can't be deactivated.</error>");
            return 1;
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to deactivate the environment <info>$environmentId</info>?", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deactivateEnvironment();
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $output->writeln("The environment <info>$environmentId</info> has been deactivated.");
        return 0;
    }
}
