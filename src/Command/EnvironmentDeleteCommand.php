<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment.')
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
            parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }
        if (!$this->operationAllowed('delete')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be deleted.</error>");
            return;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deleteEnvironment();
        // Reload the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project);

        $environmentId = $this->environment['id'];
        $message = '<info>';
        $message = "\nThe environment $environmentId has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
