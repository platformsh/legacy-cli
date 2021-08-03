<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserAddCommand extends OrganizationCommandBase
{
    protected function configure()
    {
        $this->setName('organization:user:add')
            ->addOrganizationOptions()
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Permission(s) for the user on the organization');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$organization = $this->validateOrganizationInput($input)) {
            return 1;
        }

        $email = $input->getArgument('email');

        $permissions = $input->getOption('permission');
        if (count($permissions) === 1) {
            $permissions = \preg_split('/[,\s]+/', $permissions[0]) ?: [];
        }

        $members = $organization->getMembers();
        foreach ($members as $member) {
            if ($info = $member->getUserInfo()) {
                if ($info->email === $email) {
                    $this->stdErr->writeln(\sprintf('The user <info>%s</info> already exists on the organization %s', $email, $this->api()->getOrganizationLabel($organization)));
                    if ($member->permissions != $permissions && !empty($permissions) && !$member->owner) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(\sprintf(
                            "To change the user's permissions, run:\n<comment>%s organization:user:update --name %s %s --permission %s</comment>",
                            $this->config()->get('application.executable'),
                            $organization->name,
                            \escapeshellarg($email),
                            \escapeshellarg(\implode(',', $permissions))
                        ));
                        return 1;
                    }
                    return 0;
                }
            }
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm(\sprintf('Are you sure you want to invite %s to the organization %s?', $email, $this->api()->getOrganizationLabel($organization)))) {
            return 1;
        }

        $organization->inviteMemberByEmail($email, $permissions);
        return 0;
    }
}
