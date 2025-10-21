<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\Pager\Pager;
use Platformsh\Cli\Util\Sort;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:list', description: 'Get a list of all active projects', aliases: ['projects', 'pro'])]
class ProjectListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'title' => 'Title',
        'region' => 'Region',
        'organization_name' => 'Org name',
        'organization_id' => 'Org ID',
        'organization_label' => 'Org label',
        'organization_type' => 'Org type',
        'status' => 'Status',
        'created_at' => 'Created',
    ];
    /** @var string[] */
    private array $defaultColumns = ['id', 'title', 'region'];

    public function __construct(
        private readonly Api               $api,
        private readonly Config            $config,
        private readonly PropertyFormatter $propertyFormatter,
        private readonly Io                $io,
        private readonly Table             $table,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $organizationsEnabled = $this->config->getBool('api.organizations');
        $this->defaultColumns = ['id', 'title', 'region'];
        if ($organizationsEnabled) {
            $this->defaultColumns[] = 'organization_name';
            if ($this->config->get('api.organization_types')) {
                $this->defaultColumns[] = 'organization_type';
            }
        }
        $this
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs. Disables pagination.')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Filter by region (exact match)')
            ->addHiddenOption('host', null, InputOption::VALUE_REQUIRED, 'Deprecated: replaced by --region')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own' . ($organizationsEnabled ? ' (through organizations you own)' : ''))
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number. This enables pagination, despite configuration or --count. Ignored if --pipe is specified.')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of projects to display per page. Use 0 to disable pagination. Ignored if --page is specified.');

        if ($organizationsEnabled) {
            $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'Filter by organization name or ID');
            if ($this->config->get('api.organization_types')) {
                $this->addOption('org-type', null, InputOption::VALUE_REQUIRED, 'Filter by organization type');
            }
        }

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['host'], 'The option --host is deprecated and replaced by --region. It will be removed in a future version.');

        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        // Fetch the list of projects.
        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading projects...');
        $projects = $this->api->getMyProjects($refresh ? true : null);
        $progress->done();

        // Filter the list of projects.
        $filters = [];
        if ($region = $input->getOption('region') ?: $input->getOption('host')) {
            $filters['region'] = $region;
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
        if ($input->hasOption('org-type') && $input->getOption('org-type') !== null) {
            $filters['org-type'] = $input->getOption('org-type');
        }
        $this->filterProjects($projects, $filters);

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            Sort::sortObjects($projects, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projects = array_reverse($projects, true);
        }

        // Display a message if no projects are found.
        if (empty($projects)) {
            if (!empty($filters)) {
                $filtersUsed = '<comment>--'
                    . implode('</comment>, <comment>--', array_keys($filters))
                    . '</comment>';
                $this->stdErr->writeln('No projects found (filters in use: ' . $filtersUsed . ').');

                return 0;
            }
            $this->stdErr->writeln(
                'You do not have any ' . $this->config->getStr('service.name') . ' projects yet.',
            );
            if ($this->config->isCommandEnabled('project:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To create a new project, run: <info>%s create</info>',
                    $this->config->getStr('application.executable'),
                ));
            }

            return 0;
        }

        // Display a simple list of project IDs, if --pipe is used.
        if ($input->getOption('pipe')) {
            $output->writeln(\array_map(fn(BasicProjectInfo $info): string => $info->id, $projects));

            return 0;
        }

        // Paginate the list.
        if (!$this->config->getBool('pagination.enabled') && $input->getOption('page') === null) {
            $itemsPerPage = 0;
        } elseif ($input->getOption('count') !== null) {
            $itemsPerPage = (int) $input->getOption('count');
        } else {
            $itemsPerPage = $this->config->getInt('pagination.count');
        }
        $page = (new Pager())->page($projects, (int) $input->getOption('page') ?: 1, $itemsPerPage);
        /** @var BasicProjectInfo[] $projects */
        $projects = $page->items;
        if (\count($projects) === 0) {
            $this->stdErr->writeln(\sprintf('No projects found on this page (%s)', $page->displayInfo()));
            return 1;
        }
        $machineReadable = $this->table->formatIsMachineReadable();

        $this->table->replaceDeprecatedColumns(['host' => 'region'], $input, $output);
        $this->table->removeDeprecatedColumns(['url', 'ui_url', 'endpoint', 'region_label'], '[deprecated]', $input, $output);

        $rows = [];
        foreach ($projects as $projectInfo) {
            $title = $projectInfo->title ?: '[Untitled Project]';

            // Add a warning next to the title if the project is suspended.
            if (!$machineReadable && $projectInfo->status === Subscription::STATUS_SUSPENDED) {
                $title = sprintf(
                    '<fg=white;bg=black>%s</> <fg=yellow;bg=black>(suspended)</>',
                    $title,
                );
            }

            $orgInfo = $projectInfo->organization_ref;

            $rows[] = [
                'id' => new AdaptiveTableCell($projectInfo->id, ['wrap' => false]),
                'title' => $title,
                'region' => $projectInfo->region,
                'organization_id' => $orgInfo ? $orgInfo->id : '',
                'organization_name' => $orgInfo ? $orgInfo->name : '',
                'organization_label' => $orgInfo ? $orgInfo->label : '',
                'organization_type' => $orgInfo ? (string) $orgInfo->getProperty('type', false) : '',
                'status' => $projectInfo->status,
                'created_at' => $this->propertyFormatter->format($projectInfo->created_at, 'created_at'),
                '[deprecated]' => '',
            ];
        }

        $this->tableHeader['[deprecated]'] = '[Deprecated]';

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

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

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        $executable = $this->config->getStr('application.executable');

        if ($page->pageCount > 1 && $itemsPerPage !== 0) {
            // State the command name explicitly here, as it may be displaying
            // within the 'welcome' command.
            $this->stdErr->writeln(sprintf('List all projects by running: <info>%s projects -c0</info>', $executable));
        }

        $this->stdErr->writeln([
            '',
            'Get a project by running: <info>' . $executable . ' get [id]</info>',
            "List a project's environments by running: <info>" . $executable . ' environments -p [id]</info>',
        ]);

        return 0;
    }

    /**
     * Filters the list of projects.
     *
     * @param BasicProjectInfo[] &$projects
     * @param array<string, mixed> $filters
     */
    protected function filterProjects(array &$projects, array $filters): void
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'region':
                    $projects = array_filter($projects, fn(BasicProjectInfo $project): bool => strcasecmp((string) $value, (string) $project->region) === 0);
                    break;

                case 'title':
                    $projects = array_filter($projects, fn(BasicProjectInfo $project): bool => stripos($project->title, (string) $value) !== false);
                    break;

                case 'my':
                    $ownerId = $this->api->getMyUserId();
                    $organizationsEnabled = $this->config->getBool('api.organizations');
                    $projects = array_filter($projects, function (BasicProjectInfo $project) use ($ownerId, $organizationsEnabled): bool {
                        if ($organizationsEnabled && $project->organization_ref !== null) {
                            return $project->organization_ref->owner_id === $ownerId;
                        }
                        return $project->owner_id === $ownerId;
                    });
                    break;

                case 'org':
                    // The value is an organization name or ID.
                    $isID = \preg_match('#^[\dA-HJKMNP-TV-Z]{26}$#', (string) $value) === 1;
                    $projects = \array_filter($projects, function (BasicProjectInfo $info) use ($value, $isID): bool {
                        if (!empty($info->organization_ref->id)) {
                            return $isID ? $info->organization_ref->id === $value : $info->organization_ref->name === $value;
                        }
                        return false;
                    });
                    break;

                case 'org-type':
                    $projects = \array_filter($projects, function (BasicProjectInfo $info) use ($value) {
                        if (empty($info->organization_ref)) {
                            return false;
                        }
                        return $info->organization_ref->getProperty('type', false) === $value;
                    });
                    break;
            }
        }
    }
}
