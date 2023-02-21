<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\CentralizedPermissions\UserProjectAccess;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserProjectsCommand extends OrganizationCommandBase
{
    protected $tableHeader = [
        'organization_id' => 'Organization ID',
        'project_id' => 'Project ID',
        'project_title' => 'Title',
        'roles' => 'Role(s)',
        'granted_at' => 'Granted at',
        'updated_at' => 'Updated at',
        'region' => 'Region',
    ];
    protected $defaultColumns = ['project_id', 'project_title', 'roles', 'updated_at'];

    public function isEnabled()
    {
        if (!$this->config()->getWithDefault('api.organizations', false)
            || $this->config()->getWithDefault('api.centralized_permissions', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function configure()
    {
        $this->setName('organization:user:projects')
            ->setAliases(['oups'])
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addOption('sort-granted', null, InputOption::VALUE_NONE, 'Sort the list by "granted_at" (instead of "updated_at") and display the column')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse the sort order');
        $this->setDescription('List the projects a user can access');
        $this->addOrganizationOptions();
        $this->addOption('list-all', null, InputOption::VALUE_NONE, 'List access across all organizations');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = null;
        if (!$input->getOption('list-all')) {
            $organization = $this->validateOrganizationInput($input, 'members');
            if (!$organization->hasLink('members')) {
                $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api()->getOrganizationLabel($organization, 'comment') . '.');
                return 1;
            }
        }
        $userRef = null;
        $userId = null;
        if ($email = $input->getArgument('email')) {
            if (!$organization) {
                $this->debug('Finding user by email address');
                $user = $this->api()->getUser('email=' . $email);
                $userId = $user->id;
                $userRef = UserRef::fromData($user->getData());
            } else {
                foreach ($organization->getMembers() as $member) {
                    $userRef = $member->getUserInfo();
                    if ($userRef && strtolower($userRef->email) === strtolower($email)) {
                        $this->debug('Selecting user: ' . $this->api()->getUserRefLabel($userRef));
                        $userId = $member->user_id;
                        break;
                    }
                }
                if (!$userId) {
                    $this->stdErr->writeln('User not found for email address: ' . $email);
                    return 1;
                }
            }
        } elseif ($input->isInteractive() && $organization !== null) {
            $refsById = [];
            $choices = [];
            foreach ($organization->getMembers() as $member) {
                $userRef = $member->getUserInfo();
                $label = $userRef ? $this->api()->getUserRefLabel($userRef, false) : $member->user_id;
                $choices[$member->user_id] = $label;
                $refsById[$member->user_id] = $userRef;
            }
            $default = null;
            if (isset($choices[$organization->owner_id])) {
                $choices[$organization->owner_id] .= ' (<info>owner - default</info>)';
                $default = $organization->owner_id;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $userId = $questionHelper->choose($choices, 'Enter a number to choose a user:', $default);
            $userRef = $refsById[$userId];
        } else {
            $this->stdErr->writeln('A user email address is required.');
            return 1;
        }

        $options = [];
        $reverse = $input->getOption('reverse');
        if ($input->getOption('sort-granted')) {
            $options['query']['sort'] = $reverse ? '-granted_at' : 'granted_at';
            $input->setOption('columns', $input->getOption('columns') + ['+granted_at']);
        } else {
            $options['query']['sort'] = $reverse ? '-updated_at' : 'updated_at';
        }
        if ($organization) {
            $options['query']['filter[organization_id]'] = $organization->id;
        }

        $httpClient = $this->api()->getHttpClient();
        /** @var UserProjectAccess[] $items */
        $items = [];
        $url = '/users/' . rawurlencode($userId) . '/project-access';
        $progress = new ProgressMessage($output);
        $pageNumber = 1;
        while (true) {
            $progress->showIfOutputDecorated(\sprintf('Loading projects (page %d)...', $pageNumber));
            $collection = UserProjectAccess::getPagedCollection($url, $httpClient, $options);
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
                $this->stdErr->writeln(\sprintf('No projects were found for the user %s in the organization %s.', $this->api()->getUserRefLabel($userRef), $this->api()->getOrganizationLabel($organization)));
            } else {
                $this->stdErr->writeln(\sprintf('No projects were found for the user %s.', $this->api()->getUserRefLabel($userRef)));
            }
            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $rows = [];
        foreach ($items as $item) {
            $row = [];
            $row['organization_id'] = $item->organization_id;
            $row['project_id'] = $item->project_id;
            $row['roles'] = $this->formatPermissions($item->permissions, $table->formatIsMachineReadable());
            $row['granted_at'] = $formatter->format($item->granted_at, 'granted_at');
            $row['updated_at'] = $formatter->format($item->updated_at, 'updated_at');
            $projectInfo = $item->getProjectInfo();
            $row['project_title'] = $projectInfo ? $projectInfo->title : '';
            $row['region'] = $projectInfo ? $projectInfo->region : '';
            $rows[] = $row;
        }

        if (!$table->formatIsMachineReadable()) {
            if ($organization) {
                $this->stdErr->writeln(\sprintf(
                    'Project access for the user %s in the organization %s:',
                    $this->api()->getUserRefLabel($userRef),
                    $this->api()->getOrganizationLabel($organization)
                ));
            } else {
                $this->stdErr->writeln(\sprintf(
                    'All project access for the user %s:',
                    $this->api()->getUserRefLabel($userRef)
                ));
            }
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('To view the user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', OsUtil::escapeShellArg($userRef->email))));
        }

        return 0;
    }

    /**
     * @param string[] $permissions
     * @param bool $machineReadable
     * @return string
     */
    protected function formatPermissions(array $permissions, $machineReadable)
    {
        if (empty($permissions)) {
            return '';
        }
        if ($machineReadable) {
            return json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (in_array('admin', $permissions, true)) {
            return 'Project: admin';
        }
        $byType = ['production' => '', 'staging' => '', 'development' => ''];
        foreach ($permissions as $permission) {
            $parts = explode(':', $permission, 2);
            if (count($parts) === 2) {
                list($environmentType, $role) = $parts;
                $byType[$environmentType] = $role;
            }
        }
        $lines = [];
        if (in_array('viewer', $permissions, true)) {
            $lines[] = 'Project: viewer';
        }
        if ($byType = array_filter($byType)) {
            $lines[] = 'Environment types:';
            foreach ($byType as $envType => $role) {
                $lines[] = sprintf('- %s: %s', ucfirst($envType), $role);
            }
        }
        return implode("\n", $lines);
    }
}
