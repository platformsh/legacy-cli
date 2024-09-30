<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends UserCommandBase
{
    private $tableHeader = [
        'email' => 'Email address',
        'name' => 'Name',
        'role' => 'Project role',
        'id' => 'ID',
        'granted_at' => 'Granted at',
        'updated_at' => 'Updated at',
    ];
    private $defaultColumns = ['email', 'name', 'role', 'id'];

    protected function configure()
    {
        $this
            ->setName('user:list')
            ->setAliases(['users'])
            ->setDescription('List project users');

        if ($this->centralizedPermissionsEnabled()) {
            $this->tableHeader['permissions'] = 'Permissions';
        }

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];

        if ($this->centralizedPermissionsEnabled()) {
            $result = ProjectUserAccess::getCollectionWithParent($project->getUri() . '/user-access', $this->api()->getHttpClient(), ['query' => ['page[size]' => 200]]);
            /** @var ProjectUserAccess $item */
            foreach ($result['items'] as $item) {
                $info = $item->getUserInfo();
                $rows[] = [
                    'email' => $info->email,
                    'name' => trim(sprintf('%s %s', $info->first_name, $info->last_name)),
                    'role' => $item->getProjectRole(),
                    'id' => $item->user_id,
                    'permissions' => $formatter->format($item->permissions, 'permissions'),
                    'granted_at' => $formatter->format($item->granted_at, 'granted_at'),
                    'updated_at' => $formatter->format($item->updated_at, 'updated_at'),
                ];
            }
        } else {
            foreach ($project->getUsers() as $projectAccess) {
                $info = $this->legacyUserInfo($projectAccess);
                $rows[] = [
                    'email' => $info['email'],
                    'name' => $info['display_name'],
                    'role' => $projectAccess->role,
                    'id' => $projectAccess->id,
                    'granted_at' => $formatter->format($info['created_at'], 'granted_at'),
                    'updated_at' => $formatter->format($info['updated_at'] ?: $info['created_at'], 'updated_at'),
                ];
            }
        }

        $ownerKey = null;
        foreach ($rows as $key => $row) {
            if ($row['id'] === $project->owner) {
                $ownerKey = $key;
                break;
            }
        }
        if (isset($ownerKey)) {
            $ownerRow = $rows[$ownerKey];
            $ownerRow['role'] .= ' (owner)';
            unset($rows[$ownerKey]);
            array_unshift($rows, $ownerRow);
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Users on the project %s:',
                $this->api()->getProjectLabel($project)
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln("To add a new user to the project, run: <info>$executable user:add</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln("To view a user's role(s), run: <info>$executable user:get</info>");
            $this->stdErr->writeln("To change a user's role(s), run: <info>$executable user:update</info>");
            if ($this->centralizedPermissionsEnabled() && $this->config()->get('api.teams')) {
                $organization = $this->api()->getOrganizationById($project->getProperty('organization'));
                if (in_array('teams', $organization->capabilities) && $organization->hasLink('members')) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf("To list teams with access to the project, run: <info>$executable teams -p %s</info>", $project->id));
                }
            }
        }

        return 0;
    }
}
