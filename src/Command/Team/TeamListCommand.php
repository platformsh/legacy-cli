<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Utils;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Model\ProjectRoles;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:list', description: 'List teams', aliases: ['teams'])]
class TeamListCommand extends TeamCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'label' => 'Label',
        'member_count' => '# Users',
        'project_count' => '# Projects',
        'project_permissions' => 'Permissions',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'granted_at' => 'Granted at',
    ];
    /** @var string[] */
    private array $defaultColumns = ['id', 'label', 'member_count', 'project_count', 'project_permissions'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination.')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A team property to sort by', 'label')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse order')
            ->addOption('all', 'A', InputOption::VALUE_NONE, 'List all teams in the organization (regardless of a selected project)');
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->addExample('List teams (in the current project, if any)');
        $this->addExample('List all teams in an organization', '--all');
        $this->addExample('List teams with access to a specified project, including when they were added', '--project myProjectId --columns +granted_at');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selectOrganization($input);
        if (!$organization) {
            return 1;
        }
        $selection = new Selection();
        if ($input->getOption('project') || $this->selector->getCurrentProject()) {
            $selection = $this->selector->getSelection($input);
        }

        $params = [];

        if ($sortBy = $input->getOption('sort')) {
            if ($input->getOption('reverse')) {
                $sortBy = '-' . $sortBy;
            }
            $params['sort'] = $sortBy;
        }

        $count = $input->getOption('count');
        $fetchAllPages = $count === '0';
        if (!$fetchAllPages) {
            $params['page[size]'] = $count;
        }

        $executable = $this->config->getStr('application.executable');

        // Fetch teams for a specific project.
        $projectSpecific = !$input->getOption('all') && $selection->hasProject();
        if ($projectSpecific) {
            $teamsOnProject = $this->loadTeamsOnProject($selection->getProject());
            if (!$teamsOnProject) {
                $this->stdErr->writeln(sprintf('No teams found on the project %s.', $this->api->getProjectLabel($selection->getProject(), 'comment')));
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To list all teams in the organization, run: <info>%s teams --all</info>', $executable));
                $this->stdErr->writeln(\sprintf('To add this project to a team, run: <info>%s team:project:add %s</info>', $executable, OsUtil::escapeShellArg($selection->getProject()->id)));
                return 1;
            }
            $params['filter[id][in]'] = implode(',', array_keys($teamsOnProject));
        }

        $teams = $this->loadTeams($organization, $fetchAllPages, $params);
        if (empty($teams)) {
            $this->stdErr->writeln('No teams found');
            if ($this->config->isCommandEnabled('team:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To create a new team, run: <info>%s team:create</info>', $executable));
            }
            return 1;
        }

        $machineReadable = $this->table->formatIsMachineReadable();

        $rolesUtil = new ProjectRoles();

        $rows = [];
        foreach ($teams as $team) {
            $row = [
                'id' => $team->id,
                'label' => $team->label,
                'member_count' => $this->propertyFormatter->format($team->counts['member_count']),
                'project_count' => $this->propertyFormatter->format($team->counts['project_count']),
                'project_permissions' => $rolesUtil->formatPermissions($team->project_permissions, $machineReadable),
                'created_at' => $this->propertyFormatter->format($team->created_at, 'created_at'),
                'updated_at' => $this->propertyFormatter->format($team->created_at, 'updated_at'),
                'granted_at' => isset($teamsOnProject[$team->id]) ? $this->propertyFormatter->format($teamsOnProject[$team->id], 'granted_at') : '',
            ];
            $rows[] = $row;
        }

        if (!$machineReadable) {
            if ($projectSpecific) {
                $this->stdErr->writeln(sprintf('Teams with access to the project %s:', $this->api->getProjectLabel($selection->getProject())));
            } else {
                $this->stdErr->writeln(sprintf('Teams in the organization %s:', $this->api->getOrganizationLabel($organization)));
            }
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$machineReadable) {
            $this->stdErr->writeln('');
            if ($projectSpecific) {
                $this->stdErr->writeln(\sprintf('To list all teams in the organization, run: <info>%s teams --all</info>', $executable));
            }
            $this->stdErr->writeln(\sprintf('To list team projects, run: <info>%s team:projects</info>', $executable));
            $this->stdErr->writeln(\sprintf('To list team users, run: <info>%s team:users</info>', $executable));
            $this->stdErr->writeln(\sprintf('To see all team commands run: <info>%s list team</info>', $executable));
        }

        return 0;
    }

    /**
     * Loads the information of teams that have access to a single project.
     *
     * @param Project $project The project.
     *
     * @return array<string, string>
     *     An array mapping team ID to the granted_at date of the team.
     */
    private function loadTeamsOnProject(Project $project): array
    {
        $httpClient = $this->api->getHttpClient();
        $url = $project->getUri() . '/team-access';
        $info = [];
        $progress = new ProgressMessage($this->stdErr);
        $pageNumber = 1;
        do {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading project teams (page %d)...', $pageNumber));
            }
            try {
                $response = $httpClient->get($url);
            } catch (BadResponseException $e) {
                throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
            }
            $data = (array) Utils::jsonDecode((string) $response->getBody(), true);
            foreach ($data['items'] as $item) {
                $info[$item['team_id']] = $item['granted_at'];
            }
            $progress->done();
            $url = $data['_links']['next']['href'] ?? null;
            $pageNumber++;
        } while ($url);
        return $info;
    }
}
