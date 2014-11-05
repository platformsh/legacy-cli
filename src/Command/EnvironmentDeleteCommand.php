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
        if ($environmentId == 'master') {
            $output->writeln("<error>The master environment cannot be deactivated or deleted.</error>");
            return 1;
        }

        if (!$this->operationAllowed('delete')) {
            if (!empty($this->environment['_links']['public-url'])) {
                $output->writeln("Active environments cannot be deleted.");
            }
            $output->writeln("<error>Operation not permitted: The environment '$environmentId' can't be deleted.</error>");
            // @todo make this less annoying
            $output->writeln("There may be another operation in progress - please wait and try again.");
            return 1;
        }

        if (!$this->confirm("Are you sure you want to delete the environment <info>$environmentId</info>? [Y/n] ", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deleteEnvironment();
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $environmentId = $this->environment['id'];
        $message = '<info>';
        $message .= "\nThe environment $environmentId has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
