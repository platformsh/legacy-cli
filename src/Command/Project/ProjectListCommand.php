<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:list')
            ->setAliases(['projects'])
            ->setDescription('Get a list of all active projects')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by region hostname (exact match)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('refresh-all', null, InputOption::VALUE_REQUIRED, 'Whether to refresh every project in the list', 0)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');
        $refreshAll = $input->hasOption('refresh-all') && $input->getOption('refresh-all');

        // Fetch the list of projects the user can access.
        $accountInfo = $this->api()->getMyAccount($refresh);
        $accountProjects = isset($accountInfo['projects']) ? $accountInfo['projects'] : [];

        // Filter the list of projects.
        $filters = [];
        if ($host = $input->getOption('host')) {
            $filters['host'] = $host;
        }
        if ($title = $input->getOption('title')) {
            $filters['title'] = $title;
        }
        if ($input->getOption('my')) {
            $filters['my'] = true;
        }
        $this->filterProjects($accountProjects, $filters);

        $progress = new ProgressBar($output->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading projects...');
        $progress->setFormat('%message% %current%/%max%');
        $progress->start(count($accountProjects));
        $projects = [];
        foreach ($accountProjects as $projectData) {
            if ($project = $this->api()->getProjectFromUrl($projectData['endpoint'], $refreshAll ? true : null)) {
                $projects[$project->id] = $project;
                $progress->advance();
            }
        }
        $progress->clear();

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            $this->api()->sortResources($projects, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projects = array_reverse($projects, true);
        }

        // Display a simple list of project IDs, if --pipe is used.
        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($projects));

            return 0;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        $rows = [];
        foreach ($projects as $project) {
            $title = $project->title ?: '[Untitled Project]';

            // Add a warning next to the title if the project is suspended.
            if (!$machineReadable && $project->isSuspended()) {
                $title = sprintf(
                    '<fg=white;bg=black>%s</> <fg=yellow;bg=black>(suspended)</>',
                    $title
                );
            }

            $rows[] = [
                new AdaptiveTableCell($project->id, ['wrap' => false]),
                $title,
                $project->getLink('#ui'),
            ];
        }

        $header = ['ID', 'Title', 'URL'];

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $table->render($rows, $header);

            return 0;
        }

        // Display a message if no projects are found.
        if (empty($projects)) {
            if (!empty($filters)) {
                $filtersUsed = '<comment>--'
                    . implode('</comment>, <comment>--', array_keys($filters))
                    . '</comment>';
                $this->stdErr->writeln('No projects found (filters in use: ' . $filtersUsed . ').');
            } else {
                $this->stdErr->writeln(
                    'You do not have any ' . $this->config()->get('service.name') . ' projects yet.'
                );
            }

            return 0;
        }

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->writeln('Your projects are: ');
        }

        $table->render($rows, $header);

        $commandName = $this->config()->get('application.executable');
        $this->stdErr->writeln([
            '',
            'Get a project by running: <info>' . $commandName . ' get [id]</info>',
            "List a project's environments by running: <info>" . $commandName . ' environments -p [id]</info>',
        ]);

        return 0;
    }

    /**
     * Filter the list of projects.
     *
     * @param array         &$projects
     * @param mixed[string] $filters
     */
    private function filterProjects(array &$projects, array $filters)
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'host':
                    $projects = array_filter($projects, function (array $projectData) use ($value) {
                        return $value === parse_url($projectData['endpoint'], PHP_URL_HOST);
                    });
                    break;

                case 'title':
                    $projects = array_filter($projects, function (array $projectData) use ($value) {
                        return stripos($projectData['title'], $value) !== false;
                    });
                    break;

                case 'my':
                    $ownerId = $this->api()->getMyAccount()['id'];
                    $projects = array_filter($projects, function (array $projectData) use ($ownerId) {
                        return $projectData['owner'] === $ownerId;
                    });
                    break;
            }
        }
    }
}
