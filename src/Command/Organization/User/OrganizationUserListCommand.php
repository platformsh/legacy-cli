<?php

namespace Platformsh\Cli\Command\Organization\User;

use GuzzleHttp\Url;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Resource;
use Symfony\Component\Console\Exception\InvalidArgumentException;
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
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by (created_at or updated_at). Prepend "-" to sort in descending order.', 'created_at')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Show a specific page')
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

        $options['query']['sort'] = $input->getOption('sort');

        $options['query']['page[size]'] = $itemsPerPage;
        $fetchAllPages = !$this->config()->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
            $options['query']['page[size]'] = 100;
        }

        if (!$fetchAllPages && ($pageId = $input->getOption('page'))) {
            $options['query'] += $this->parsePageId($pageId);
        }

        $httpClient = $this->api()->getHttpClient();
        $url = $organization->getLink('members');
        /** @var Member[] $members */
        $members = [];

        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading users...');
        try {
            do {
                $result = Member::getPagedCollection($url, $httpClient, $options);
                $members = array_merge($members, $result['items']);
                $url = $result['next'];
            } while (!empty($url) && $fetchAllPages);
        } finally {
            $progress->done();
        }

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($members as $member) {
            $userInfo = $member->getUserInfo();
            if (!$userInfo) {
                throw new \RuntimeException('Member user info not found');
            }
            $row = [
                'id' => $member->id,
                'first_name' => $userInfo->first_name,
                'last_name' => $userInfo->last_name,
                'email' => $userInfo->email,
                'username' => $userInfo->username,
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

        if (!$table->formatIsMachineReadable()) {
            $more = false;
            if (!$fetchAllPages) {
                $this->stdErr->writeln('');
                if (($total = $this->total($members)) && $total > count($members)) {
                    $this->stdErr->writeln(sprintf('More users are available (displaying <info>%d</info>, total <info>%d</info>)', count($members), $total));
                    $more = true;
                } elseif (isset($result['next']) || isset($result['previous'])) {
                    $this->stdErr->writeln('More users are available');
                    $more = true;
                }
            }
            $this->stdErr->writeln('');
            if ($more) {
                $this->stdErr->writeln('Show all users with: <info>--count 0</info>');
                if (isset($result['next']) && ($pageId = $this->pageId($result['next'], 'after'))) {
                    $this->stdErr->writeln(sprintf('View the next page with: <info>--page %s</info>', OsUtil::escapeShellArg($pageId)));
                }
                if (isset($result['previous']) && ($pageId = $this->pageId($result['previous'], 'before'))) {
                    $this->stdErr->writeln(sprintf('View the previous page with: <info>--page %s</info>', OsUtil::escapeShellArg($pageId)));
                }
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To get full user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', '[email]')));
            } else {
                $this->stdErr->writeln(\sprintf('To get full user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', '[email]')));
                $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:add', '[email]')));
                $this->stdErr->writeln(\sprintf('To update a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:update', '[email]')));
                $this->stdErr->writeln(\sprintf('To remove a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:delete', '[email]')));
            }
        }

        return 0;
    }

    /**
     * @param string $pageId
     * @return array<string, string> A list of parameters to add to the URL.
     */
    private function parsePageId($pageId)
    {
        if (strpos($pageId, 'a') !== 0 && strpos($pageId, 'b') !== 0) {
            throw new InvalidArgumentException('Invalid page ID: ' . $pageId);
        }

        return [$pageId[0] === 'a' ? 'page[after]' : 'page[before]' => substr($pageId, 1)];
    }

    /**
     * Generates an identifier for another page.
     *
     * @param string $url
     *   The URL returned as the next or previous page.
     * @param string $rel
     *   The page type ('before' or 'after').
     * @return string|null
     */
    private function pageId($url, $rel)
    {
        $page = Url::fromString($url)->getQuery()->get('page');
        if (isset($page[$rel])) {
            return $rel[0] . $page[$rel];
        }
        return null;
    }

    /**
     * Finds the total count in a list of resources, if any.
     *
     * @param Resource[] $resources
     * @return ?int
     */
    private function total($resources)
    {
        $res = end($resources);
        if ($res && ($collection = $res->getParentCollection())) {
            $collectionData = $collection->getData();
            if (isset($collectionData['count'])) {
                return $collectionData['count'];
            }
        }
        return null;
    }
}
