<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class DomainDeleteCommand extends EnvironmentCommand
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
                'The project id'
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
            return;
        }
        
        $client = $this->getPlatformClient($this->project['endpoint'] . "/domains/" . $name);
        $client->deleteDomain();
        
        $message = '<info>';
        $message = "\nThe given domain has been successfuly deleted from the project. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
