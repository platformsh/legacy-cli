<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMergeCommand extends EnvironmentCommand
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
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
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

        if (!$this->confirm("Are you sure you want to merge <info>$environmentId</info> with its parent, <info>$parentId</info>? [Y/n] ", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->mergeEnvironment();
        // Reload the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project);

        $output->writeln("The environment <info>$environmentId</info> has been merged with <info>$parentId</info>.");
        return 0;
    }
}
