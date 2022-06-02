<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationDeleteCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:delete';
    protected static $defaultDescription = 'Delete an organization';

    private $api;
    private $selector;
    private $questionHelper;

    public function __construct(Config $config, Api $api, Selector $selector, QuestionHelper $questionHelper)
    {
        $this->api = $api;
        $this->selector = $selector;
        $this->questionHelper = $questionHelper;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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
