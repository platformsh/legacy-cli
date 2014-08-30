<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class DomainAddCommand extends DomainCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:add')
            ->setDescription('Add a new domain to the project.')
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

        $name = $input->getArgument('name');
        if (empty($name)) {
            $output->writeln("<error>You must specify the name of the domain.</error>");
            return;
        }

        // @Todo: Improve this with a better dialog box.
        $dialog = $this->getHelperSet()->get('dialog');
        $answer = $dialog->ask($output, "Is your domain a wildcard (yes / no)? \n");
        $wildcard = ($answer == "yes") ? true : false;

        $client = $this->getPlatformClient($this->project['endpoint']);
        $client->addDomain(array('name' => $name, 'wildcard' => $wildcard));

        $message = '<info>';
        $message = "\nThe given domain has been successfuly added to the project. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
