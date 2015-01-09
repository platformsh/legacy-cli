<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to delete');
        $this->addProjectOption()->addEnvironmentOption();
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
                $output->writeln("The environment <error>$environmentId</error> is active and therefore can't be deleted.");
                $output->writeln("Please deactivate the environment first.");
                return 1;
            }
            $output->writeln(
              "Operation not permitted: The environment <error>$environmentId</error> can't be deleted."
            );
            return 1;
        }

        // Check that the environment does not have children.
        // @todo remove this check when Platform's behavior is fixed
        foreach ($this->getEnvironments($this->project) as $environment) {
            if ($environment['parent'] == $this->environment['id']) {
                $output->writeln("The environment <error>$environmentId</error> has children and therefore can't be deleted.");
                $output->writeln("Please delete the environment's children first.");
                return 1;
            }
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to delete the environment <info>$environmentId</info>?", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->deleteEnvironment();
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $environmentId = $this->environment['id'];

        $output->writeln("The environment <info>$environmentId</info> has been deleted.");
    }
}
