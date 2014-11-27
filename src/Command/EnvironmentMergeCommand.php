<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMergeCommand extends EnvironmentCommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:merge')
            ->setDescription('Merge an environment.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project ID'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];

        if (!$this->operationAllowed('merge')) {
            $output->writeln("<error>Operation not permitted: The environment '$environmentId' can't be merged.</error>");
            return 1;
        }

        $parentId = $this->environment['parent'];

        if (!$this->getHelper('question')->confirm("Are you sure you want to merge <info>$environmentId</info> with its parent, <info>$parentId</info>?", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->mergeEnvironment();
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $output->writeln("The environment <info>$environmentId</info> has been merged with <info>$parentId</info>.");
        return 0;
    }
}
