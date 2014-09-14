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
            return;
        }
        if (!$this->operationAllowed('activate')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be activated.</error>");
            return;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->activateEnvironment();

        $environmentId = $this->environment['id'];
        $message = '<info>';
        $message .= "\nThe environment $environmentId has been activated. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
