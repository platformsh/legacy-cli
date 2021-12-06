<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\ProjectStub;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:list')
            ->setAliases(['projects', 'pro'])
            ->setDescription('Get a list of all active projects')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of project IDs')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by region hostname (exact match)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Filter by title (case-insensitive search)')
            ->addOption('my', null, InputOption::VALUE_NONE, 'Display only the projects you own')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order');

        if ($this->config()->getWithDefault('api.organizations', false)) {
            $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'Filter by organization name');
        }

        Table::configureInput($this->getDefinition());
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
            $orgName = $input->getOption('org');
            $organization = $this->api()->getClient()->getOrganizationByName($orgName);
            if (!$organization) {
                $this->stdErr->writeln(\sprintf('Organization not found: <error>%s</error>', $orgName));
                return 1;
            }
            $filters['org'] = $organization;
        }
        $this->filterProjectStubs($projectStubs, $filters);

        // Sort the list of projects.
        if ($input->getOption('sort')) {
            $this->api()->sortResources($projectStubs, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projectStubs = array_reverse($projectStubs, true);
        }

        // Display a simple list of project IDs, if --pipe is used.
        if ($input->getOption('pipe')) {
            $output->writeln(\array_filter($projectStubs, function (ProjectStub $stub) {
                return $stub->id;
            }));

            return 0;
        }

        // Convert old column names for backwards compatibility.
        if ($input->hasOption('columns') && ($columns = $input->getOption('columns'))) {
            if (count($columns) === 1) {
                $columns = preg_split('/\s*,\s*/', $columns[0]);
            }
            $replace = ['host' => 'region', 'url' => 'ui_url'];
            foreach ($replace as $old => $new) {
                if (($pos = \array_search($old, $columns, true)) !== false) {
                    $this->stdErr->writeln(\sprintf('<options=reverse>DEPRECATED</> The column <comment>%s</comment> has been replaced by <info>%s</info>.', $old, $new));
                    $columns[$pos] = $new;
                }
            }
            $input->setOption('columns', $columns);
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();

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
        ];
        $defaultColumns = ['id', 'title', 'region'];
        if ($this->config()->getWithDefault('api.organizations', false)) {
            $defaultColumns[] = 'organization_name';
        }

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
            ];
        }

        // Display a simple table (and no messages) if the --format is
        // machine-readable (e.g. csv or tsv).
        if ($machineReadable) {
            $table->render($rows, $header, $defaultColumns);

            return 0;
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

        // Display the projects.
        if (empty($filters)) {
            $this->stdErr->writeln('Your projects are: ');
        }

        $table->render($rows, $header, $defaultColumns);

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
                    $ownerId = $this->api()->getMyUserId();
                    $organizationsEnabled = $this->config()->getWithDefault('api.organizations', false);
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
