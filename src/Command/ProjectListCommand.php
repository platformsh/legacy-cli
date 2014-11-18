<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('projects')
            ->setDescription('Get a list of all active projects.')
            ->addOption(
              'pipe',
              null,
              InputOption::VALUE_NONE,
              'Output a simple list of project IDs.'
            )
            ->addOption(
              'refresh',
              null,
              InputOption::VALUE_OPTIONAL,
              'Whether to refresh the list.',
              1
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        $projects = $this->getProjects($refresh);

        if ($input->getOption('pipe') || !$this->isTerminal($output)) {
            $output->writeln(array_keys($projects));
            return 0;
        }

        $rows = array();
        foreach ($projects as $projectId => $project) {
            $row = array();
            $row[] = $projectId;
            $row[] = $project['name'];
            $row[] = $project['uri'];
            $rows[] = $row;
        }

        $output->writeln("\nYour projects are: ");
        $table = new Table($output);
        $table->setHeaders(array('ID', 'Name', "URL"))
            ->addRows($rows);
        $table->render();

        $output->writeln("\nGet a project by running <info>platform get [id]</info>.");
        $output->writeln("Delete a project by running <info>platform project:delete [id]</info>.");
        $output->writeln("List a project's environments by running <info>platform environments</info>.\n");
    }
}
