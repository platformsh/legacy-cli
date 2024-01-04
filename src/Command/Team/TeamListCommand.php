<?php
namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Model\ProjectRoles;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TeamListCommand extends TeamCommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'label' => 'Label',
        'member_count' => '# Users',
        'project_count' => '# Projects',
        'project_permissions' => 'Permissions',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
    ];
    private $defaultColumns = ['id', 'label', 'member_count', 'project_count', 'project_permissions'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('team:list')
            ->setAliases(['teams'])
            ->setDescription('List teams')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination.')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A team property to sort by', 'label')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse order')
            ->addOrganizationOptions(true);
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->selectOrganization($input);
        if (!$organization) {
            return 1;
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

        $teams = $this->loadTeams($organization, $fetchAllPages, $params);

        $executable = $this->config()->get('application.executable');
        if (empty($teams)) {
            $this->stdErr->writeln('No teams found.');
            if ($this->config()->isCommandEnabled('team:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To create a new team, run: <info>%s team:create</info>', $executable));
            }
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $machineReadable = $table->formatIsMachineReadable();

        $rolesUtil = new ProjectRoles();

        $rows = [];
        foreach ($teams as $team) {
            $row = [
                'id' => $team->id,
                'label' => $team->label,
                'member_count' => $formatter->format($team->counts['member_count']),
                'project_count' => $formatter->format($team->counts['project_count']),
                'project_permissions' => $rolesUtil->formatPermissions($team->project_permissions, $machineReadable),
                'created_at' => $formatter->format($team->created_at, 'created_at'),
                'updated_at' => $formatter->format($team->created_at, 'updated_at'),
            ];
            $rows[] = $row;
        }

        if (!$machineReadable) {
            $this->stdErr->writeln(sprintf('Teams in the organization %s:', $this->api()->getOrganizationLabel($organization)));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$machineReadable) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To list team projects, run: <info>%s team:projects</info>', $executable));
            $this->stdErr->writeln(\sprintf('To list team users, run: <info>%s team:users</info>', $executable));
            $this->stdErr->writeln(\sprintf('To see all team commands run: <info>%s list team</info>', $executable));
        }

        return 0;
    }
}
