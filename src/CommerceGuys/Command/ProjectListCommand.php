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

        $client = $this->getAccountClient();
        $data = $client->getProjects();
        $project_rows = array();
        foreach ($data['projects'] as $project) {
            $project_row = array();
            $project_row[] = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($project['name']));
            $project_row[] = $project['name'];
            $project_row[] = $project['uri'];
            $project_rows[] = $project_row;
        }

        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('ID', 'Name', "URL"))
            ->setRows($project_rows);
        $table->render($output);
        $output->writeln("\nYou can delete any project by running <info>platform project:delete [id]</info>.\n");
    }
}
