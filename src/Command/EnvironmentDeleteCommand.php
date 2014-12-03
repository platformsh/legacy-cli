<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;

use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment');
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

        if (!$this->operationAllowed('delete')) {
            if (!empty($this->environment['_links']['public-url'])) {
                $output->writeln("Active environments cannot be deleted.");
            }
            $output->writeln("<error>Operation not permitted: The environment '$environmentId' can't be deleted.</error>");
            return 1;
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to delete the environment <info>$environmentId</info>?", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deleteEnvironment();
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $environmentId = $this->environment['id'];

        $output->writeln("The environment <info>$environmentId</info> has been deleted.");
    }
}
