<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:backup')
            ->setDescription('Make a backup of an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to back up');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];
        if (!$this->operationAvailable('backup')) {
            $output->writeln(
              "Operation not available: the environment <error>$environmentId</error> cannot be backed up"
            );
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $data = $client->backupEnvironment();

        $output->writeln("A backup of environment <info>$environmentId</info> has been created.");

        if (!empty($data['_embedded']['activities'][0]['payload']['backup_name'])) {
            $name = $data['_embedded']['activities'][0]['payload']['backup_name'];
            $output->writeln("Backup name: <info>$name</info>");
        }
        return 0;
    }
}
