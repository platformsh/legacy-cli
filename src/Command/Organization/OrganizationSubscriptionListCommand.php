<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationSubscriptionListCommand extends OrganizationCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('organization:subscription:list')
            ->setAliases(['organization:subscriptions'])
            ->setDescription('List subscriptions within an organization')
            ->addOrganizationOptions();
        Table::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input);

        $subscriptions = $organization->getSubscriptions();

        if (empty($subscriptions)) {
            $this->stdErr->writeln(\sprintf('No subscriptions were found belonging to the organization %s.', $this->api()->getOrganizationLabel($organization, 'error')));
            return 1;
        }

        $headers = [
            'id' => 'Subscription ID',
            'project_id' => 'Project ID',
            'project_title' => 'Title',
            'project_region' => 'Region',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
        ];
        $defaultColumns = ['id', 'project_id', 'project_title', 'project_region'];

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $row = $subscription->getProperties();
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Subscriptions belonging to the organization <info>%s</info>', $this->api()->getOrganizationLabel($organization)));
        }

        $table->render($rows, $headers, $defaultColumns);

        return 0;
    }
}
