<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentBackupCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:backup')
            ->setDescription('Backup an environment.')
            ->addArgument(
                'project-id',
                InputArgument::OPTIONAL,
                'The project id'
            )
            ->addArgument(
                'environment-id',
                InputArgument::OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }
        if (!$this->validateArguments($input, $output)) {
            return;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->backupEnvironment();

        $environmentId = $input->getArgument('environment-id');
        $message = '<info>';
        $message = "\nA backup of environment $environmentId has been created. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
