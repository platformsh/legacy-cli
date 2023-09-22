<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
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

        $members = $organization->getMembers();

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
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To get full user details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:get', '[email]')));
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:add', '[email]')));
            $this->stdErr->writeln(\sprintf('To update a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:update', '[email]')));
            $this->stdErr->writeln(\sprintf('To remove a user, run: <info>%s</info>', $this->otherCommandExample($input, 'org:user:delete', '[email]')));
        }

        return 0;
    }
}
