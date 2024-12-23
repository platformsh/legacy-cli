<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:delete', description: 'Delete an organization')]
class OrganizationDeleteCommand extends OrganizationCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input);

        $subscriptions = $organization->getSubscriptions();
        if (!empty($subscriptions)) {
            $this->stdErr->writeln(\sprintf('The organization %s still owns project(s), so it cannot be deleted.', $this->api->getOrganizationLabel($organization, 'comment')));
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You would need to delete the projects or transfer them to another organization first.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf("To list the organization's projects, run: <info>%s</info>", $this->otherCommandExample($input, 'org:subscriptions')));
            return 1;
        }

        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to delete the organization %s?', $this->api->getOrganizationLabel($organization)), false)) {
            return 1;
        }

        $organization->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The organization ' . $this->api->getOrganizationLabel($organization) . ' was deleted.');

        return 0;
    }
}
