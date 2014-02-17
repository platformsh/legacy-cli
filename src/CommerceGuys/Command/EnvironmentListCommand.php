<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentListCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:list')
            ->setDescription('Get a list of all environments.')
            ->addArgument(
                'project-id',
                InputArgument::OPTIONAL,
                'The project id'
            );
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }
        if (!$this->validateArguments($input, $output)) {
            return;
        }

        $rows = array();
        foreach ($this->getEnvironments() as $environment) {
            $row = array();
            $row[] = $environment['id'];
            $row[] = $environment['title'];
            $row[] = $environment['_links']['public-url']['href'];
            $rows[] = $row;
        }

        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Name', "URL"))
            ->setRows($rows);
        $table->render($output);
    }
}
