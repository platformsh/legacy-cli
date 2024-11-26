<?php
namespace Platformsh\Cli\Command\Team\Project;

use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Team\TeamProjectAccess;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TeamProjectListCommand extends TeamCommandBase
{
    const MAX_COUNT = 200;

    private $tableHeader = [
        'id' => 'Project ID',
        'title' => 'Project title',
        'granted_at' => 'Date added',
        'updated_at' => 'Updated at',
    ];
    private $defaultColumns = ['id', 'title', 'granted_at'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('team:project:list')
            ->setAliases(['team:projects', 'team:pro'])
            ->setDescription('List projects in a team')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page (max: ' . self::MAX_COUNT . '). Use 0 to disable pagination')
            ->addOrganizationOptions()
            ->addTeamOption();
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];
        $options['query']['sort'] = 'project_title';

        $count = $input->getOption('count');
        $itemsPerPage = (int) $this->config()->getWithDefault('pagination.count', 20);
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > self::MAX_COUNT) {
                $this->stdErr->writeln('The --count must be a number between 1 and ' . self::MAX_COUNT . ', or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }
        $options['query']['range'] = $itemsPerPage;

        $fetchAllPages = !$this->config()->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        $httpClient = $this->api()->getHttpClient();
        /** @var TeamProjectAccess[] $projects */
        $projects = [];
        $url = $team->getUri() . '/project-access';
        $progress = new ProgressMessage($output);
        $pageNumber = 1;
        do {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading projects (page %d)...', $pageNumber));
            }
            $result = TeamProjectAccess::getCollectionWithParent($url, $httpClient, $options);
            $progress->done();
            $projects = \array_merge($projects, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        } while ($url && $fetchAllPages);

        if (empty($projects)) {
            $this->stdErr->writeln(\sprintf('No projects were found in the team %s.', $this->getTeamLabel($team)));
            $this->stdErr->writeln('');
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln(\sprintf('To add project(s), run: <info>%s team:project:add</info>', $executable));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = [
                'id' => new AdaptiveTableCell($project->project_id, ['wrap' => false]),
                'title' => $project->project_title,
                'granted_at' => $formatter->format($project->granted_at, 'granted_at'),
                'updated_at' => $formatter->format($project->updated_at, 'updated_at'),
            ];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Projects in the team %s:', $this->getTeamLabel($team)));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            if (isset($collection['next'])) {
                $this->stdErr->writeln('More projects are available');
                $this->stdErr->writeln('List all items with: <info>--count 0</info> (<info>-c0</info>)');
            }

            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add project(s) to the team, run: <info>%s team:project:add [ids...]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To delete a project from the team, run: <info>%s team:project:delete [id]</info>', $executable));
        }

        return 0;
    }
}
