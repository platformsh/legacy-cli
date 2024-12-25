<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\AccessApi;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'List project users', aliases: ['users'])]
class UserListCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'email' => 'Email address',
        'name' => 'Name',
        'role' => 'Project role',
        'id' => 'ID',
        'granted_at' => 'Granted at',
        'updated_at' => 'Updated at',
    ];
    /** @var string[] */
    private array $defaultColumns = ['email', 'name', 'role', 'id'];

    public function __construct(
        private readonly AccessApi $accessApi,
        private readonly Api               $api,
        private readonly Config            $config,
        private readonly PropertyFormatter $propertyFormatter,
        private readonly Selector          $selector,
        private readonly Table             $table,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        if ($this->accessApi->centralizedPermissionsEnabled()) {
            $this->tableHeader['permissions'] = 'Permissions';
        }

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $project = $selection->getProject();

        $rows = [];

        if ($this->accessApi->centralizedPermissionsEnabled()) {
            $result = ProjectUserAccess::getCollectionWithParent($project->getUri() . '/user-access', $this->api->getHttpClient(), ['query' => ['page[size]' => 200]]);
            /** @var ProjectUserAccess $item */
            foreach ($result['items'] as $item) {
                $info = $item->getUserInfo();
                $rows[] = [
                    'email' => $info->email,
                    'name' => trim(sprintf('%s %s', $info->first_name, $info->last_name)),
                    'role' => $item->getProjectRole(),
                    'id' => $item->user_id,
                    'permissions' => $this->propertyFormatter->format($item->permissions, 'permissions'),
                    'granted_at' => $this->propertyFormatter->format($item->granted_at, 'granted_at'),
                    'updated_at' => $this->propertyFormatter->format($item->updated_at, 'updated_at'),
                ];
            }
        } else {
            foreach ($project->getUsers() as $projectAccess) {
                $info = $this->accessApi->legacyUserInfo($projectAccess);
                $rows[] = [
                    'email' => $info['email'],
                    'name' => $info['display_name'],
                    'role' => $projectAccess->role,
                    'id' => $projectAccess->id,
                    'granted_at' => $this->propertyFormatter->format($info['created_at'], 'granted_at'),
                    'updated_at' => $this->propertyFormatter->format($info['updated_at'] ?: $info['created_at'], 'updated_at'),
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

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Users on the project %s:',
                $this->api->getProjectLabel($project),
            ));
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln("To add a new user to the project, run: <info>$executable user:add</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln("To view a user's role(s), run: <info>$executable user:get</info>");
            $this->stdErr->writeln("To change a user's role(s), run: <info>$executable user:update</info>");
            if ($this->accessApi->centralizedPermissionsEnabled() && $this->config->getBool('api.teams')) {
                $organization = $this->api->getOrganizationById($project->getProperty('organization'));
                if ($organization && in_array('teams', $organization->capabilities) && $organization->hasLink('members')) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf("To list teams with access to the project, run: <info>$executable teams -p %s</info>", $project->id));
                }
            }
        }

        return 0;
    }
}
