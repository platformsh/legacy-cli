<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:user:delete', description: 'Remove a user from an organization')]
class OrganizationUserDeleteCommand extends OrganizationCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addArgument('email', InputArgument::REQUIRED, 'The email address of the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The 'create-member' link shows the user has the ability to read/write members.
        $organization = $this->selector->selectOrganization($input, 'create-member');

        $email = $input->getArgument('email');

        $member = $this->api->loadMemberByEmail($organization, $email);
        if (!$member) {
            $this->stdErr->writeln(\sprintf('User not found: <error>%s</error>', $email));
            return 1;
        }
        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to delete the user <comment>%s</comment> from the organization %s?', $email, $this->api->getOrganizationLabel($organization, 'comment')))) {
            return 1;
        }

        $member->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The user was successfully deleted.');

        return 0;
    }
}
