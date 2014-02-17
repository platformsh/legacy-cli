<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class ProjectListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:list')
            ->setDescription('Get a list of all active projects.');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }

        $projects = $this->getProjects(true);
        $rows = array();
        foreach ($projects as $project) {
            $row = array();
            $row[] = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($project['name']));
            $row[] = $project['name'];
            $row[] = $project['uri'];
            $rows[] = $row;
        }

        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Name', "URL"))
            ->setRows($rows);
        $table->render($output);
        $output->writeln("\nYou can delete any project by running <info>platform project:delete [id]</info>.\n");
    }
}
