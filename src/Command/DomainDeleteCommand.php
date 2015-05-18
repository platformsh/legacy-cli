<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainDeleteCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('domain:delete')
          ->setDescription('Delete a domain from the project')
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
        $this->validateInput($input, $output);

        $name = $input->getArgument('name');
        if (empty($name)) {
            $output->writeln("<error>You must specify the name of the domain.</error>");

            return 1;
        }

        $domain = $this->getSelectedProject()
                       ->getDomain($name);
        if (!$domain) {
            $output->writeln("Domain not found: <error>$name</error>");

            return 1;
        }

        if (!$this->getHelper('question')
                  ->confirm("Are you sure you want to delete the domain <info>$name</info>?", $input, $output)
        ) {
            return 0;
        }

        $domain->delete();

        $output->writeln("The domain <info>$name</info> has been deleted.");

        return 0;
    }
}
