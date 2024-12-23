<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Member;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:user:list', description: 'List organization users', aliases: ['org:users'])]
class OrganizationUserListCommand extends OrganizationCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'username' => 'Username',
        'permissions' => 'Permissions',
        'owner' => 'Owner?',
        'mfa_enabled' => 'MFA enabled?',
        'sso_enabled' => 'SSO enabled?',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
    ];
    /** @var string[] */
    private array $defaultColumns = ['id', 'email', 'owner', 'permissions'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination.')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by (created_at or updated_at)', 'created_at')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse the sort order')
            ->setHiddenAliases(['organization:users']);
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input, 'members');

        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api->getOrganizationLabel($organization, 'comment') . '.');
            return 1;
        }

        $options = [];

        $count = $input->getOption('count');
        $itemsPerPage = $this->config->getInt('pagination.count');
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 100) {
                $this->stdErr->writeln('The --count must be a number between 1 and 100, or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }

        if ($sort = $input->getOption('sort')) {
            if ($input->getOption('reverse')) {
                $sort = '-' . $sort;
            }
            $options['query']['sort'] = $sort;
        }

        $options['query']['page[size]'] = $itemsPerPage;
        $fetchAllPages = !$this->config->getBool('pagination.enabled');
        if ($count === '0') {
            $fetchAllPages = true;
            $options['query']['page[size]'] = 100;
        }

        $httpClient = $this->api->getHttpClient();
        $url = $organization->getLink('members');
        /** @var Member[] $members */
        $members = [];

        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading users...');
        try {
            do {
                $result = Member::getCollectionWithParent($url, $httpClient, $options);
                $members = array_merge($members, $result['items']);
                $url = $result['collection']->getNextPageUrl();
            } while (!empty($url) && $fetchAllPages);
        } finally {
            $progress->done();
        }
        if (empty($members)) {
            $this->stdErr->writeln('No users found');
            return 1;
        }

        $rows = [];
        foreach ($members as $member) {
            $userInfo = $member->getUserInfo();
            $row = [
                'id' => $member->id,
                'first_name' => $userInfo ? $userInfo->first_name : '',
                'last_name' => $userInfo ? $userInfo->last_name : '',
                'email' => $userInfo ? $userInfo->email : '',
                'username' => $userInfo ? $userInfo->username : '',
                'owner' => $this->propertyFormatter->format($member->owner, 'owner'),
                'mfa_enabled' => $userInfo && isset($userInfo->mfa_enabled) ? $this->propertyFormatter->format($userInfo->mfa_enabled, 'mfa_enabled') : '',
                'sso_enabled' => $userInfo && isset($userInfo->sso_enabled) ? $this->propertyFormatter->format($userInfo->sso_enabled, 'sso_enabled') : '',
                'permissions' => $this->propertyFormatter->format($member->permissions, 'permissions'),
                'updated_at' => $this->propertyFormatter->format($member->updated_at, 'updated_at'),
                'created_at' => $this->propertyFormatter->format($member->created_at, 'created_at'),
            ];
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('Users in the organization ' . $this->api->getOrganizationLabel($organization) . ':');
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        $total = $result['collection']->getTotalCount();
        $moreAvailable = !$fetchAllPages && $total > count($members);
        if ($moreAvailable) {
            if (!$this->table->formatIsMachineReadable() || $this->stdErr->isDecorated()) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln(sprintf('More users are available (displaying <info>%d</info>, total <info>%d</info>)', count($members), $total));
            $this->stdErr->writeln('Show all users with: <info>--count 0</info> (<info>-c0</info>)');
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To get full user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', '[email]')));
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:add', '[email]')));
            $this->stdErr->writeln(\sprintf('To update a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:update', '[email]')));
            $this->stdErr->writeln(\sprintf('To remove a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:delete', '[email]')));
        }

        return 0;
    }
}
