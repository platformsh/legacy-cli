<?php

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class OrganizationCommandBase extends CommandBase
{
    public function isEnabled()
    {
        if (!$this->config()->get('api.organizations')) {
            return false;
        }
        return parent::isEnabled();
    }

    /**
     * Adds the organization --org and --name options.
     *
     * @return self
     */
    protected function addOrganizationOptions()
    {
        $this->addOption('org', 'o', InputOption::VALUE_REQUIRED, 'The organization name');
        return $this;
    }

    /**
     * @param InputInterface $input
     * @param array $names
     *
     * @return bool
     */
    private function optionsClash(InputInterface $input, array $names)
    {
        foreach ($names as $name) {
            if (!$input->hasOption($name) || !$input->getOption($name)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param InputInterface $input
     *
     * @return Organization|null
     */
    protected function validateOrganizationInput(InputInterface $input)
    {
        $client = $this->api()->getClient();

        if ($name = $input->getOption('org')) {
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
        $id = $questionHelper->choose($options, 'Enter a number to choose an organization (<fg=cyan>-o</>):');
        return $byId[$id];
    }
}
