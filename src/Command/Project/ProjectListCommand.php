<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\Pager\Pager;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\ProjectStub;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class ProjectListCommand extends CommandBase
{
    protected static $defaultName = 'project:list|projects|pro';
    protected static $defaultDescription = 'Get a list of all active projects';

    private $api;
    private $config;
    private $formatter;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs. This disables pagination.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by region hostname (exact match)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (starting from 1)', '1')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'The number of projects to display per page. The default is based on the terminal height. Use 0 to disable pagination.');

        if ($this->config->getWithDefault('api.organizations', false)) {
            $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'Filter by organization name');
        }

        $this->table->configureInput($this->getDefinition());
        $this->formatter->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        // Fetch the list of projects.
        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading projects...');
        $projectStubs = $this->api->getProjectStubs($refresh ? true : null);
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
            $orgName = $input->getOption('org');
            $organization = $this->api->getClient()->getOrganizationByName($orgName);
            if (!$organization) {
                $this->stdErr->writeln(\sprintf('Organization not found: <error>%s</error>', $orgName));
                return 1;
            }
            $filters['org'] = $organization;
        }
        $this->filterProjectStubs($projectStubs, $filters);

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            $this->api->sortResources($projectStubs, $input->getOption('sort'));
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
                    'You do not have any ' . $this->config->get('service.name') . ' projects yet.'
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
        if (!$this->config->getWithDefault('pagination.enabled', true)) {
            $itemsPerPage = 0;
        } elseif ($input->getOption('count') !== null) {
            $itemsPerPage = (int)$input->getOption('count');
        } else {
            // Find a default --count based on the terminal height (minimum 10).
            // Deduct 24 lines for consistency with the welcome command.
            $itemsPerPage = \max(10, (new Terminal())->getHeight() - 24);
            if ($itemsPerPage > \count($projectStubs)) {
                $itemsPerPage = \count($projectStubs);
            }
        }
        $page = (new Pager())->page($projectStubs, (int) $input->getOption('page'), (int) $itemsPerPage);
        /** @var ProjectStub[] $projectStubs */
        $projectStubs = $page->items;
        if (\count($projectStubs) === 0) {
            $this->stdErr->writeln(\sprintf('No projects found on this page (%s)', $page->displayInfo()));
            return 1;
        }

        $machineReadable = $this->table->formatIsMachineReadable();

        $header = [
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
        $defaultColumns = ['id', 'title', 'region'];
        if ($this->config->getWithDefault('api.organizations', false)) {
            $defaultColumns[] = 'organization_name';
        }

        $this->table->replaceDeprecatedColumns(['url' => 'ui_url', 'host' => 'region'], $input, $output);

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

            $org_info = $projectStub->getOrganizationInfo();

            $rows[] = [
                'id' => new AdaptiveTableCell($projectStub->id, ['wrap' => false]),
                'title' => $title,
                'ui_url' => $projectStub->getProperty('uri', false),
                'region' => $projectStub->region,
                'region_label' => $projectStub->region_label,
                'organization_id' => $org_info ? $org_info->id : '',
                'organization_name' => $org_info ? $org_info->name : '',
                'organization_label' => $org_info ? $org_info->label : '',
                'status' => $projectStub->status,
                'endpoint' => $projectStub->endpoint,
                'created_at' => $this->formatter->format($projectStub->created_at, 'created_at'),
            ];
        }

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $this->table->render($rows, $header, $defaultColumns);

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

        $this->table->render($rows, $header, $defaultColumns);

        $executable = $this->config->get('application.executable');

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
     * @param mixed[string] $filters
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
                    $ownerId = $this->api->getMyUserId();
                    $organizationsEnabled = $this->config->getWithDefault('api.organizations', false);
                    $projects = array_filter($projects, function (ProjectStub $project) use ($ownerId, $organizationsEnabled) {
                        if ($organizationsEnabled && ($organizationInfo = $project->getOrganizationInfo()) !== null) {
                            return $organizationInfo->owner_id === $ownerId;
                        }
                        return $project->owner === $ownerId;
                    });
                    break;

                case 'org':
                    /** @var Organization $value */
                    $projects = array_filter($projects, function (ProjectStub $project) use ($value) {
                        return $project->getProperty('organization_id', false, false) === $value->id;
                    });
                    break;
            }
        }
    }
}
