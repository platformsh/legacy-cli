<?php

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class OrganizationCommandBase extends CommandBase
{
    /**
     * Adds the organization --name option.
     *
     * @return self
     */
    protected function addOrganizationOptions()
    {
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'The organization name');
        return $this;
    }

    /**
     * @param InputInterface $input
     *
     * @return Organization|null
     */
    protected function validateOrganizationInput(InputInterface $input)
    {
        $client = $this->api()->getClient();

        if ($name = $input->getOption('name')) {
            $organization = $client->getOrganizationByName($name);
            if (!$organization) {
                $this->stdErr->writeln(\sprintf('Organization name not found: <error>%s</error>', $name));
                return null;
            }
            return $organization;
        }

        if (!$input->isInteractive() || !($organizations = $client->listOrganizationsWithMember($this->api()->getMyUserId()))) {
            $this->stdErr->writeln('An organization <error>--name</error> is required.');
            return null;
        }

        $this->api()->sortResources($organizations, 'name');
        $options = [];
        $byId = [];
        foreach ($organizations as $organization) {
            $options[$organization->id] = $this->api()->getOrganizationLabel($organization, false);
            $byId[$organization->id] = $organization;
        }
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $id = $questionHelper->choose($options, 'Enter a number to choose an organization:');
        return $byId[$id];
    }
}
