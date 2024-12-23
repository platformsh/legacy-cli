<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Model\ProjectRoles;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\CentralizedPermissions\UserExtendedAccess;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:user:projects', description: 'List the projects a user can access', aliases: ['oups'])]
class OrganizationUserProjectsCommand extends OrganizationCommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
        'organization_id' => 'Organization ID',
        'organization_name' => 'Organization',
        'organization_label' => 'Organization label',
        'project_id' => 'Project ID',
        'project_title' => 'Title',
        'roles' => 'Role(s)',
        'granted_at' => 'Granted at',
        'updated_at' => 'Updated at',
        'region' => 'Region',
    ];

    /** @var string[] */
    protected array $defaultColumns = ['project_id', 'project_title', 'roles', 'updated_at'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool('api.centralized_permissions')
            && $this->config->getBool('api.organizations')
            && parent::isEnabled();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addHiddenOption('sort-granted', null, InputOption::VALUE_NONE, 'Deprecated option: unused')
            ->addHiddenOption('reverse', null, InputOption::VALUE_NONE, 'Deprecated option: unused');
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('list-all', null, InputOption::VALUE_NONE, 'List access across all organizations');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = null;
        if (!$input->getOption('list-all')) {
            $organization = $this->selector->selectOrganization($input, 'members');
            if (!$organization->hasLink('members')) {
                $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api->getOrganizationLabel($organization, 'comment') . '.');
                return 1;
            }
        }
        if ($email = $input->getArgument('email')) {
            if (!$organization) {
                $this->io->debug('Finding user by email address');
                $user = $this->api->getUser('email=' . $email);
                $userId = $user->id;
                $userRef = UserRef::fromData($user->getData());
            } else {
                $member = $this->api->loadMemberByEmail($organization, $email);
                if (!$member) {
                    $this->stdErr->writeln('User not found for email address: ' . $email);
                    return 1;
                }
                $userId = $member->user_id;
                $userRef = $member->getUserInfo();
            }
        } elseif ($input->isInteractive() && $organization !== null) {
            $member = $this->chooseMember($organization);
            $userId = $member->user_id;
            $userRef = $member->getUserInfo();
        } else {
            $this->stdErr->writeln('A user email address is required.');
            return 1;
        }

        $options = [];
        if ($organization) {
            $options['query']['filter[organization_id]'] = $organization->id;
        }

        $options['query']['filter[resource_type]'] = 'project';

        $httpClient = $this->api->getHttpClient();
        /** @var UserExtendedAccess[] $items */
        $items = [];
        $url = '/users/' . rawurlencode($userId) . '/extended-access';
        $progress = new ProgressMessage($output);
        $pageNumber = 1;
        while (true) {
            $progress->showIfOutputDecorated(\sprintf('Loading projects (page %d)...', $pageNumber));
            $collection = UserExtendedAccess::getPagedCollection($url, $httpClient, $options);
            $progress->done();
            $items = \array_merge($items, $collection['items']);
            if (count($collection['items']) > 0 && isset($collection['next']) && $collection['next'] !== $url) {
                $url = $collection['next'];
                $pageNumber++;
                continue;
            }
            break;
        }

        if (empty($items)) {
            if ($pageNumber > 1) {
                $this->stdErr->writeln('No items were found on this page.');
                return 0;
            }
            if ($organization) {
                $this->stdErr->writeln(\sprintf('No projects were found for the user %s in the organization %s.', $this->api->getUserRefLabel($userRef), $this->api->getOrganizationLabel($organization)));
            } else {
                $this->stdErr->writeln(\sprintf('No projects were found for the user %s.', $this->api->getUserRefLabel($userRef)));
            }
            return 0;
        }

        $rolesUtil = new ProjectRoles();

        $rows = [];
        foreach ($items as $item) {
            $row = [];
            $row['organization_id'] = $item->organization_id;
            $row['project_id'] = $item->resource_id;
            $row['roles'] = $rolesUtil->formatPermissions($item->permissions, $this->table->formatIsMachineReadable());
            $row['granted_at'] = $this->propertyFormatter->format($item->granted_at, 'granted_at');
            $row['updated_at'] = $this->propertyFormatter->format($item->updated_at, 'updated_at');
            $projectInfo = $item->getProjectInfo();
            $row['project_title'] = $projectInfo ? $projectInfo->title : '';
            $row['region'] = $projectInfo ? $projectInfo->region : '';
            $orgInfo = $item->getOrganizationInfo();
            $row['organization_name'] = $orgInfo ? $orgInfo->name : '';
            $row['organization_label'] = $orgInfo ? $orgInfo->label : '';
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($organization) {
                $this->stdErr->writeln(\sprintf(
                    'Project access for the user %s in the organization %s:',
                    $this->api->getUserRefLabel($userRef),
                    $this->api->getOrganizationLabel($organization),
                ));
            } else {
                $this->stdErr->writeln(\sprintf(
                    'All project access for the user %s:',
                    $this->api->getUserRefLabel($userRef),
                ));
            }
        }

        $defaultColumns = $this->defaultColumns;
        if (!$organization) {
            $defaultColumns[] = 'organization_name';
        }

        $this->table->render($rows, $this->tableHeader, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('To view the user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', OsUtil::escapeShellArg($userRef->email))));
        }

        return 0;
    }
}
