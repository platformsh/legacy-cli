<?php
namespace Platformsh\Cli\Command\Organization;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationDeleteCommand extends OrganizationCommandBase
{
    protected $stability = 'ALPHA';

    protected function configure()
    {
        $this->setName('organization:delete')
            ->setDescription('Delete an organization')
            ->addOrganizationOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input);

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
