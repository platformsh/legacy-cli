<?php
namespace Platformsh\Cli\Command\Organization;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationDeleteCommand extends OrganizationCommandBase
{

    protected function configure()
    {
        $this->setName('organization:delete')
            ->setDescription('Delete an organization')
            ->addOrganizationOptions(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input);

        $subscriptions = $organization->getSubscriptions();
        if (!empty($subscriptions)) {
            $this->stdErr->writeln(\sprintf('The organization %s still owns project(s), so it cannot be deleted.', $this->api()->getOrganizationLabel($organization, 'comment')));
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You would need to delete the projects or transfer them to another organization first.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf("To list the organization's projects, run: <info>%s</info>", $this->otherCommandExample($input, 'org:subscriptions')));
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (!$questionHelper->confirm(\sprintf('Are you sure you want to delete the organization %s?', $this->api()->getOrganizationLabel($organization)), false)) {
            return 1;
        }

        $organization->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The organization ' . $this->api()->getOrganizationLabel($organization) . ' was deleted.');

        return 0;
    }
}
