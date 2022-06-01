<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserDeleteCommand extends OrganizationCommandBase
{
    protected function configure()
    {
        $this->setName('organization:user:delete')
            ->setDescription('Remove a user from an organization')
            ->addOrganizationOptions()
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // The 'create-member' link shows the user has the ability to read/write members.
        $organization = $this->validateOrganizationInput($input, 'create-member');

        $email = $input->getArgument('email');

        $members = $organization->getMembers();
        $member = false;
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm(\sprintf('Are you sure you want to delete the user <comment>%s</comment> from the organization %s?', $email, $this->api()->getOrganizationLabel($organization, 'comment')))) {
            return 1;
        }

        $member->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The user was successfully deleted.');

        return 0;
    }
}
