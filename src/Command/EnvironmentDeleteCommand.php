<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentDeleteCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment.')
            ->addArgument(
                'environment-id',
                InputArgument::OPTIONAL,
                'The environment id'
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deleteEnvironment();
        // Refresh the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project, TRUE);

        $environmentId = $input->getArgument('environment-id');
        $message = '<info>';
        $message = "\nThe environment $environmentId has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
