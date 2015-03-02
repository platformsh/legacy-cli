<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:backup')
            ->setDescription('Make a backup of an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to back up')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the backup to complete');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];
        if (!$selectedEnvironment->operationAvailable('backup')) {
            $output->writeln("Operation not available: the environment <error>$environmentId</error> cannot be backed up");
            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $output->writeln("Requested backup of <info>$environmentId</info>");

        if (!$input->getOption('no-wait')) {
            $output->writeln("Waiting for the backup to complete...");
            $activity->wait();
            $output->writeln("A backup of environment <info>$environmentId</info> has been created");
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Backup name: <info>$name</info>");
        }
        return 0;
    }
}
