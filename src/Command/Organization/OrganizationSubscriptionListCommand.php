<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:subscription:list', description: 'List subscriptions within an organization', aliases: ['org:subs'])]
class OrganizationSubscriptionListCommand extends OrganizationCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'Subscription ID',
        'project_id' => 'Project ID',
        'project_title' => 'Title',
        'project_region' => 'Region',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
    ];

    /** @var string[] */
    private array $defaultColumns = ['id', 'project_id', 'project_title', 'project_region'];

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHiddenAliases(['organization:subscriptions'])
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number. This enables pagination, despite configuration or --count.')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination. Ignored if --page is specified.');
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];
        $options['query']['filter']['status']['value'][] = 'active';
        $options['query']['filter']['status']['value'][] = 'suspended';
        $options['query']['filter']['status']['operator'] = 'IN';

        $count = $input->getOption('count');
        $itemsPerPage = $this->config->getInt('pagination.count');
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 50) {
                $this->stdErr->writeln('The --count must be a number between 1 and 50, or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }
        $options['query']['range'] = $itemsPerPage;

        $fetchAllPages = !$this->config->getBool('pagination.enabled');
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $pageNumber = $input->getOption('page');
        if ($pageNumber === null) {
            $pageNumber = 1;
        } else {
            $fetchAllPages = false;
        }
        $options['query']['page'] = $pageNumber;

        $organization = $this->selector->selectOrganization($input);

        $httpClient = $this->api->getHttpClient();
        $subscriptions = [];
        $url = $organization->getUri() . '/subscriptions';
        $progress = new ProgressMessage($output);
        while (true) {
            $progress->showIfOutputDecorated(\sprintf('Loading subscriptions (page %d)...', $pageNumber));
            $collection = Subscription::getPagedCollection($url, $httpClient, $options);
            $progress->done();
            $subscriptions = \array_merge($subscriptions, $collection['items']);
            if ($fetchAllPages && count($collection['items']) > 0 && isset($collection['next']) && $collection['next'] !== $url) {
                $url = $collection['next'];
                $pageNumber++;
                continue;
            }
            break;
        }

        if (empty($subscriptions)) {
            if ($pageNumber > 1) {
                $this->stdErr->writeln('No subscriptions were found on this page.');
                return 0;
            }
            $this->stdErr->writeln(\sprintf('No subscriptions were found belonging to the organization %s.', $this->api->getOrganizationLabel($organization)));
            return 0;
        }

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $row = $subscription->getProperties();
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $title = \sprintf('Subscriptions belonging to the organization <info>%s</info>', $this->api->getOrganizationLabel($organization));
            if (($pageNumber > 1 || isset($collection['next'])) && !$fetchAllPages) {
                $title .= \sprintf(' (page %d)', $pageNumber);
            }
            $this->stdErr->writeln($title);
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable() && isset($collection['next'])) {
            $this->stdErr->writeln(\sprintf('More subscriptions are available on the next page (<info>--page %d</info>)', $pageNumber + 1));
            $this->stdErr->writeln('List all items with: <info>--count 0</info> (<info>-c0</info>)');
        }

        return 0;
    }
}
