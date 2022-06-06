<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserListCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:user:list|organization:users';
    protected static $defaultDescription = 'List users in an organization';

    private $api;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(Api $api, Config $config, PropertyFormatter $formatter, Selector $selector, Table $table)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->formatter->configureInput($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->selector->selectOrganization($input, 'members');

        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api->getOrganizationLabel($organization, 'comment') . '.');
            return 1;
        }

        $members = $organization->getMembers();

        $headers = ['id' => 'ID', 'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'username' => 'Username', 'permissions' => 'Permissions', 'owner' => 'Owner?', 'created_at' => 'Created at', 'updated_at' => 'Updated at'];
        $defaultColumns = ['id', 'email', 'owner', 'permissions'];
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
                'owner' => $this->formatter->format($member->owner, 'owner'),
                'permissions' => $this->formatter->format($member->permissions, 'permissions'),
                'updated_at' => $this->formatter->format($member->updated_at, 'updated_at'),
                'created_at' => $this->formatter->format($member->created_at, 'created_at'),
            ];
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('Users in the organization ' . $this->api->getOrganizationLabel($organization) . ':');
        }

        $this->table->render($rows, $headers, $defaultColumns);

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
