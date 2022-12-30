<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\Pager\Pager;
use Platformsh\Client\Model\ProjectStub;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends CommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'title' => 'Title',
        'ui_url' => 'Web URL',
        'region' => 'Region',
        'region_label' => 'Region label',
        'organization_name' => 'Organization',
        'organization_id' => 'Organization ID',
        'organization_label' => 'Organization label',
        'status' => 'Status',
        'endpoint' => 'Endpoint',
        'created_at' => 'Created',
    ];
    private $defaultColumns = ['id', 'title', 'region'];

    protected function configure()
    {
        $organizationsEnabled = $this->config()->getWithDefault('api.organizations', false);
        $this->defaultColumns = ['id', 'title', 'region'];
        if ($organizationsEnabled) {
            $this->defaultColumns[] = 'organization_name';
        }
        $this
            ->setName('project:list')
            ->setAliases(['projects', 'pro'])
            ->setDescription('Get a list of all active projects')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs. Disables pagination.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by region hostname (exact match)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own' . ($organizationsEnabled ? ' (through organizations you own)' : ''))
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number. This enables pagination, despite configuration or --count. Ignored if --pipe is specified.')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of projects to display per page. Use 0 to disable pagination. Ignored if --page is specified.');

        if ($organizationsEnabled) {
            $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'Filter by organization name or ID');
        }

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        // Fetch the list of projects.
        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading projects...');
        $projectStubs = $this->api()->getProjectStubs($refresh ? true : null);
        $progress->done();

        // Filter the list of projects.
        $filters = [];
        if ($host = $input->getOption('host')) {
            $filters['host'] = $host;
        }
        if (($title = $input->getOption('title')) !== null) {
            $filters['title'] = $title;
        }
        if ($input->getOption('my')) {
            $filters['my'] = true;
        }
        if ($input->hasOption('org') && $input->getOption('org') !== null) {
            $filters['org'] = $input->getOption('org');
        }
        $this->filterProjectStubs($projectStubs, $filters);

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            $this->api()->sortResources($projectStubs, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projectStubs = array_reverse($projectStubs, true);
        }

        // Display a message if no projects are found.
        if (empty($projectStubs)) {
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

        // Display a simple list of project IDs, if --pipe is used.
        if ($input->getOption('pipe')) {
            $output->writeln(\array_map(function (ProjectStub $stub) { return $stub->id; }, $projectStubs));

            return 0;
        }

        // Paginate the list.
        if (!$this->config()->getWithDefault('pagination.enabled', true) && $input->getOption('page') === null) {
            $itemsPerPage = 0;
        } elseif ($input->getOption('count') !== null) {
            $itemsPerPage = (int)$input->getOption('count');
        } else {
            $itemsPerPage = (int) $this->config()->getWithDefault('pagination.count', 20);
        }
        $page = (new Pager())->page($projectStubs, (int) $input->getOption('page') ?: 1, $itemsPerPage);
        /** @var ProjectStub[] $projectStubs */
        $projectStubs = $page->items;
        if (\count($projectStubs) === 0) {
            $this->stdErr->writeln(\sprintf('No projects found on this page (%s)', $page->displayInfo()));
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

        $table->replaceDeprecatedColumns(['url' => 'ui_url', 'host' => 'region'], $input, $output);

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($projectStubs as $projectStub) {
            $title = $projectStub->title ?: '[Untitled Project]';

            // Add a warning next to the title if the project is suspended.
            if (!$machineReadable && $projectStub->status === Subscription::STATUS_SUSPENDED) {
                $title = sprintf(
                    '<fg=white;bg=black>%s</> <fg=yellow;bg=black>(suspended)</>',
                    $title
                );
            }

            $orgInfo = $projectStub->getOrganizationInfo();

            $rows[] = [
                'id' => new AdaptiveTableCell($projectStub->id, ['wrap' => false]),
                'title' => $title,
                'ui_url' => $projectStub->getProperty('uri', false),
                'region' => $projectStub->region,
                'region_label' => $projectStub->region_label,
                'organization_id' => $orgInfo ? $orgInfo->id : '',
                'organization_name' => $orgInfo ? $orgInfo->name : '',
                'organization_label' => $orgInfo ? $orgInfo->label : '',
                'status' => $projectStub->status,
                'endpoint' => $projectStub->endpoint,
                'created_at' => $formatter->format($projectStub->created_at, 'created_at'),
            ];
        }

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $table->render($rows, $this->tableHeader, $this->defaultColumns);

            return 0;
        }

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->write('Your projects are');
            if ($page->pageCount > 1) {
                $this->stdErr->write(\sprintf(' (%s)', $page->displayInfo()));
            }
            $this->stdErr->writeln(':');
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        $executable = $this->config()->get('application.executable');

        if ($page->pageCount > 1 && $itemsPerPage !== 0) {
            $this->stdErr->writeln('List all projects by running: <info>' . $executable . ' projects --count 0</info>');
        }

        $this->stdErr->writeln([
            '',
            'Get a project by running: <info>' . $executable . ' get [id]</info>',
            "List a project's environments by running: <info>" . $executable . ' environments -p [id]</info>',
        ]);

        return 0;
    }

    /**
     * Filter the list of projects.
     *
     * @param ProjectStub[]     &$projects
     * @param array<string, mixed> $filters
     */
    protected function filterProjectStubs(array &$projects, array $filters)
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'host':
                    $projects = array_filter($projects, function (ProjectStub $project) use ($value) {
                        return $value === parse_url($project->endpoint, PHP_URL_HOST);
                    });
                    break;

                case 'title':
                    $projects = array_filter($projects, function (ProjectStub $project) use ($value) {
                        return stripos($project->title, $value) !== false;
                    });
                    break;

                case 'my':
                    $ownerId = $this->api()->getMyUserId();
                    $organizationsEnabled = $this->config()->getWithDefault('api.organizations', false);
                    $projects = array_filter($projects, function (ProjectStub $project) use ($ownerId, $organizationsEnabled) {
                        if ($organizationsEnabled && ($orgInfo = $project->getOrganizationInfo()) !== null) {
                            return $orgInfo->owner_id === $ownerId;
                        }
                        return $project->owner === $ownerId;
                    });
                    break;

                case 'org':
                    // The value is an organization name or ID.
                    $isID = \preg_match('#^[\dA-HJKMNP-TV-Z]{26}$#', $value) === 1;
                    $projects = \array_filter($projects, function (ProjectStub $projectStub) use ($value, $isID) {
                        if ($orgInfo = $projectStub->getOrganizationInfo()) {
                            return $isID ? $orgInfo->id === $value : $orgInfo->name === $value;
                        }
                        return false;
                    });
                    break;
            }
        }
    }
}
