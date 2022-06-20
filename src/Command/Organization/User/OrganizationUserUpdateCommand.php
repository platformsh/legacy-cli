<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserUpdateCommand extends OrganizationCommandBase
{
    protected function configure()
    {
        $this->setName('organization:user:update')
            ->setDescription('Update an organization user')
            ->addOrganizationOptions()
            ->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Permission(s) for the user on the organization');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // The 'create-member' link shows the user has the ability to read/write members.
        $organization = $this->validateOrganizationInput($input, 'create-member');

        $permissionsOption = $input->getOption('permission');
        $permissions = false;
        if (\count($permissionsOption) === 1) {
            if ($permissionsOption[0] === 'none') {
                $permissions = [];
            } else {
                $permissions = \preg_split('/[,\s]+/', $permissionsOption[0]) ?: [];
            }
        } elseif (\count($permissionsOption) > 1) {
            $permissions = $permissionsOption;
        }

        if (empty($permissions)) {
            $this->stdErr->writeln('At least one --permission is required.');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $members = $organization->getMembers();
        $email = $input->getArgument('email');
        $member = false;
        if ($email) {
            foreach ($members as $m) {
                if ($info = $m->getUserInfo()) {
                    if ($info->email === $email) {
                        $member = $m;
                        break;
                    }
                }
            }
            if (!$member) {
                $this->stdErr->writeln(\sprintf('User not found: <error>%s</error>', $email));
                return 1;
            }
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('A user email address is required.');
            return 1;
        } else {
            $choices = [];
            $byId = [];
            foreach ($members as $m) {
                $choices[$m->id] = $this->memberLabel($m);
                $byId[$m->id] = $m;
            }
            if (!$choices) {
                $this->stdErr->writeln('No users found.');
                return 1;
            }
            $memberId = $questionHelper->choose($choices, 'Enter a number to choose a user to update:');
            $member = $byId[$memberId];
        }

        $this->stdErr->writeln(\sprintf('Updating the user <info>%s</info> on the organization %s', $this->memberLabel($member), $this->api()->getOrganizationLabel($organization)));
        $this->stdErr->writeln('');

        if ($member->permissions == $permissions) {
            $this->stdErr->writeln(\sprintf("The user's permissions are already set to: <info>%s</info>", $this->formatPermissions($member->permissions)));
            return 0;
        }

        if ($member->owner) {
            $this->stdErr->writeln('The user is the owner of the organization, so does not need permissions.');
            return 1;
        }

        $this->stdErr->writeln('Summary of changes:');

        $this->stdErr->writeln('  Permissions:');
        $same = \array_intersect($member->permissions, $permissions);
        foreach ($same as $permission) {
            $this->stdErr->writeln('      ' . $permission);
        }
        $remove = \array_diff($member->permissions, $permissions);
        foreach ($remove as $permission) {
            $this->stdErr->writeln('    <fg=red>- ' . $permission . '</>');
        }
        $add = \array_diff($permissions, $member->permissions);
        foreach ($add as $permission) {
            $this->stdErr->writeln('    <fg=green>+ ' . $permission . '</>');
        }

        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Are you sure you want to make these changes?')) {
            return 1;
        }

        $result = $member->update(['permissions' => $permissions]);
        $new = $result->getProperty('permissions', false) ?: [];

        $this->stdErr->writeln(\sprintf("The user's permissions are now: <info>%s</info>", $this->formatPermissions($new)));

        return 0;
    }

    protected function formatPermissions(array $permissions)
    {
        return $permissions ? \implode(', ', $permissions) : '[none]';
    }
}
