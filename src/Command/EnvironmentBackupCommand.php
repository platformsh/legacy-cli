<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;

use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:backup')
            ->setDescription('Make a backup of an environment');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];
        if (!$this->operationAllowed('backup')) {
            $output->writeln("<error>Operation not permitted: Can't make a backup of the environment '$environmentId''.</error>");
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->backupEnvironment();

        $output->writeln("A backup of environment <info>$environmentId</info> has been created.");
    }
}
