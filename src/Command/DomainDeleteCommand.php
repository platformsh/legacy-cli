<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class DomainDeleteCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:delete')
            ->setDescription('Delete a domain from the project.')
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'The name of the domain'
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project ID'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }
        // @Todo: If we want to do this check, we need to override the operationAllowed to work with domains too.
        /*
        if (!$this->operationAllowed('delete')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be deleted.</error>");
            return;
        }
        */

        $name = $input->getArgument('name');
        if (empty($name)) {
            $output->writeln("<error>You must specify the name of the domain.</error>");
            return 1;
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to delete the domain <info>$name</info>?", $input, $output)) {
            return 0;
        }

        $client = $this->getPlatformClient($this->project['endpoint'] . "/domains/" . $name);
        $client->deleteDomain();

        $output->writeln("The domain <info>$name</info> has been deleted.");
        return 0;
    }
}
