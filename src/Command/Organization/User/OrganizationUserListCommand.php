<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Member;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserListCommand extends OrganizationCommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'username' => 'Username',
        'permissions' => 'Permissions',
        'owner' => 'Owner?',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
    ];
    private $defaultColumns = ['id', 'email', 'owner', 'permissions'];

    protected function configure()
    {
        $this->setName('organization:user:list')
            ->setDescription('List organization users')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination.')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by (created_at or updated_at)', 'created_at')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse the sort order')
            ->setAliases(['org:users'])
            ->setHiddenAliases(['organization:users'])
            ->addOrganizationOptions();
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input, 'members');

        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api()->getOrganizationLabel($organization, 'comment') . '.');
            return 1;
        }

        $options = [];

        $count = $input->getOption('count');
        $itemsPerPage = (int) $this->config()->getWithDefault('pagination.count', 20);
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
        $fetchAllPages = !$this->config()->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
            $options['query']['page[size]'] = 100;
        }

        $httpClient = $this->api()->getHttpClient();
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

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($members as $member) {
            $userInfo = $member->getUserInfo();
            $row = [
                'id' => $member->id,
                'first_name' => $userInfo ? $userInfo->first_name : '',
                'last_name' => $userInfo ? $userInfo->last_name : '',
                'email' => $userInfo ? $userInfo->email : '',
                'username' => $userInfo ? $userInfo->username : '',
                'owner' => $formatter->format($member->owner, 'owner'),
                'permissions' => $formatter->format($member->permissions, 'permissions'),
                'updated_at' => $formatter->format($member->updated_at, 'updated_at'),
                'created_at' => $formatter->format($member->created_at, 'created_at'),
            ];
            $rows[] = $row;
        }
        /** @var Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('Users in the organization ' . $this->api()->getOrganizationLabel($organization) . ':');
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        $total = $result['collection']->getTotalCount();
        $moreAvailable = !$fetchAllPages && $total > count($members);
        if ($moreAvailable) {
            if (!$table->formatIsMachineReadable() || $this->stdErr->isDecorated()) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln(sprintf('More users are available (displaying <info>%d</info>, total <info>%d</info>)', count($members), $total));
            $this->stdErr->writeln('Show all users with: <info>--count 0</info>');
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To get full user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', '[email]')));
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:add', '[email]')));
            $this->stdErr->writeln(\sprintf('To update a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:update', '[email]')));
            $this->stdErr->writeln(\sprintf('To remove a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:delete', '[email]')));
        }

        return 0;
    }
}
