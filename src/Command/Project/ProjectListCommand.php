<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('project:list')
          ->setAliases(array('projects'))
          ->setDescription('Get a list of all active projects')
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

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($projects));

            return 0;
        }

        $rows = array();
        foreach ($projects as $project) {
            $rows[] = array(
              $project->id,
              $project->title,
              $project->getLink('#ui'),
            );
        }

        $this->stdErr->writeln("Your projects are: ");
        $table = new Table($output);
        $table->setHeaders(array('ID', 'Name', "URL"))
              ->addRows($rows);
        $table->render();

        $this->stdErr->writeln("\nGet a project by running <info>platform get [id]</info>");
        $this->stdErr->writeln("List a project's environments by running <info>platform environments</info>");

        return 0;
    }
}
