<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentListCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environments')
            ->setDescription('Get a list of all environments.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            );
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $rows = array();
        foreach ($this->getEnvironments($this->project, TRUE) as $environment) {
            $row = array();
            $row[] = $environment['id'];
            $row[] = $environment['title'];
            $row[] = $environment['_links']['public-url']['href'];
            $rows[] = $row;
        }

        $output->writeln("Your environments are: ");
        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Name', "URL"))
            ->setRows($rows);
        $table->render($output);

        $output->writeln("\nDelete the current environment by running <info>platform environment:delete</info>.");
        $output->writeln("Backup the current environment by running <info>platform environment:backup</info>.");
        $output->writeln("Merge the current environment by running <info>platform environment:merge</info>.");
        $output->writeln("Sync the current environment by running <info>platform environment:synchronize</info>.");
        $output->writeln("Branch a new environment by running <info>platform environment:branch</info>.");
        $output->writeln("Note: You can specify a different environment using the --environment option.\n");
    }
}
