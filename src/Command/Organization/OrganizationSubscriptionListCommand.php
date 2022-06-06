<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationSubscriptionListCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:subscription:list|organization:subscriptions';
    protected static $defaultDescription = 'List subscriptions within an organization';

    private $api;
    private $selector;
    private $table;

    public function __construct(Config $config, Api $api, Selector $selector, Table $table)
    {
        $this->api = $api;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->selector->selectOrganization($input);

        $subscriptions = $organization->getSubscriptions();

        if (empty($subscriptions)) {
            $this->stdErr->writeln(\sprintf('No subscriptions were found belonging to the organization %s.', $this->api->getOrganizationLabel($organization, 'error')));
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


        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Subscriptions belonging to the organization <info>%s</info>', $this->api->getOrganizationLabel($organization)));
        }

        $this->table->render($rows, $headers, $defaultColumns);

        return 0;
    }
}
