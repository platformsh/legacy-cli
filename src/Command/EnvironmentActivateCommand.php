<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment.')
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

        if (!$this->operationAllowed('activate')) {
            if (!empty($this->environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                return 0;
            }
            $output->writeln("<error>Operation not permitted: The environment '$environmentId' can't be activated.</error>");
            $output->writeln("There may be another operation in progress - please wait and try again.");
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->activateEnvironment();

        $output->writeln("The environment <info>$environmentId</info> has been activated.");
        return 0;
    }
}
