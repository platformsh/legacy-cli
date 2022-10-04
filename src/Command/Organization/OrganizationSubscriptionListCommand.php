<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationSubscriptionListCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:subscription:list|organization:subscriptions';
    protected static $defaultDescription = 'List subscriptions within an organization';

    private $api;
    private $config;
    private $selector;
    private $table;

    public function __construct(Config $config, Api $api, Selector $selector, Table $table)
    {
        $this->api = $api;
        $this->config = $config;
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
        $this->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number. This enables pagination, despite configuration or --count.')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination. Ignored if --page is specified.');
        $this->table->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = [];
        $query['filter']['status']['value'][] = 'active';
        $query['filter']['status']['value'][] = 'suspended';
        $query['filter']['status']['operator'] = 'IN';

        $count = $input->getOption('count');
        $itemsPerPage = (int) $this->config->getWithDefault('pagination.count', 20);
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 50) {
                $this->stdErr->writeln('The --count must be a number between 1 and 50, or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }
        $query['range'] = $itemsPerPage;

        $fetchAllPages = !$this->config->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $pageNumber = $input->getOption('page');
        if ($pageNumber === null) {
            $pageNumber = 1;
        } else {
            $fetchAllPages = false;
        }
        $query['page'] = $pageNumber;

        $organization = $this->selector->selectOrganization($input);

        $httpClient = $this->api->getHttpClient();
        $subscriptions = [];
        $url = $organization->getUri() . '/subscriptions';
        $progress = new ProgressMessage($output);
        while (true) {
            $progress->showIfOutputDecorated(\sprintf('Loading subscriptions (page %d)...', $pageNumber));
            $collection = $this->getPagedCollection($url, $httpClient, $query);
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
            $title = \sprintf('Subscriptions belonging to the organization <info>%s</info>', $this->api->getOrganizationLabel($organization));
            if (($pageNumber > 1 || isset($collection['next'])) && !$fetchAllPages) {
                $title .= \sprintf(' (page %d)', $pageNumber);
            }
            $this->stdErr->writeln($title);
        }

        $this->table->render($rows, $headers, $defaultColumns);

        if (!$this->table->formatIsMachineReadable() && isset($collection['next'])) {
            $this->stdErr->writeln(\sprintf('More subscriptions are available on the next page (<info>--page %d</info>)', $pageNumber + 1));
            $this->stdErr->writeln('List all items with: <info>--count 0</info> (<info>-c0</info>)');
        }

        return 0;
    }

    /**
     * Returns a list of subscriptions.
     *
     * This is the equivalent of Subscription::getCollection() with pagination
     * logic.
     *
     * If 'items' is non-empty and if a non-null 'next' URL is returned, this
     * call may be repeated with the new URL to fetch the next page.
     *
     * Use $query['page'] to specify a page number explicitly.
     *
     * @param string $url
     * @param ClientInterface $client
     * @param array $query
     *
     * @return array{'items': Subscription[], 'next': ?string}
     *@todo move this into the API client library
     *
     */
    private function getPagedCollection($url, ClientInterface $client, array $query = [])
    {
        $request = new Request('get', Uri::withQueryValues(new Uri($url), $query));
        $data = Subscription::send($request, $client);
        $items = Subscription::wrapCollection($data, $url, $client);

        $nextUrl = null;
        if (isset($data['_links']['next']['href'])) {
            $nextUrl = UriResolver::resolve(new Uri($url), $data['_links']['next']['href'])->__toString();
        }

        return ['items' => $items, 'next' => $nextUrl];
    }
}
