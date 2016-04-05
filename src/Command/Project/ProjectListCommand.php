<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:list')
            ->setAliases(['projects'])
            ->setDescription('Get a list of all active projects')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs.')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list.', 1);
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        $projects = $this->api->getProjects($refresh ? true : null);

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($projects));

            return 0;
        }

        $table = new Table($input, $output);

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = [
                $project->id,
                $project->title,
                $project->getLink('#ui'),
            ];
        }

        $header = ['ID', 'Title', 'URL'];

        if ($table->formatIsMachineReadable()) {
            $table->render($rows, $header);

            return 0;
        }

        $this->stdErr->writeln("Your projects are: ");

        $table->render($rows, $header);

        $this->stdErr->writeln("\nGet a project by running <info>" . self::$config->get('application.executable') . " get [id]</info>");
        $this->stdErr->writeln("List a project's environments by running <info>" . self::$config->get('application.executable') . " environments</info>");

        return 0;
    }
}
