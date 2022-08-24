<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Url;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of items per page (default 25, maximum 50, 0 means no pagination)')
            ->addOrganizationOptions();
        Table::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = [];
        $options['query']['filter']['status']['value'][] = 'active';
        $options['query']['filter']['status']['value'][] = 'suspended';
        $options['query']['filter']['status']['operator'] = 'IN';

        $count = $input->getOption('count');
        $range = 25;
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 50) {
                $this->stdErr->writeln('The --count must be a number between 1 and 50, or 0 to disable pagination.');
                return 1;
            }
            $range = $count;
        }
        $options['query']['range'] = $range;

        $fetchAllPages = !$this->config()->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $page = $input->getOption('page');
        if ($page !== null) {
            $fetchAllPages = false;
            $options['query']['page'] = $page;
        }

        $organization = $this->validateOrganizationInput($input);
        $httpClient = $this->api()->getHttpClient();

        $subscriptions = [];
        $url = $organization->getUri() . '/subscriptions';
        $pageCount = 0;
        $nextPageNumber = $page ?: 1;
        $progress = new ProgressMessage($output);
        while (true) {
            $progress->showIfOutputDecorated(\sprintf('Loading subscriptions (page %d)...', $nextPageNumber));
            $collection = $this->getPagedCollection($url, $httpClient, $options);
            $progress->done();
            $pageCount++;
            $nextPageNumber++;
            $subscriptions = \array_merge($subscriptions, $collection['items']);
            if ($fetchAllPages && count($collection['items']) > 0 && isset($collection['next']) && $collection['next'] !== $url) {
                $url = $collection['next'];
                continue;
            }
            break;
        }

        if (empty($subscriptions)) {
            if (!empty($page)) {
                $this->stdErr->writeln('No subscriptions were found on this page.');
                return 0;
            }
            $this->stdErr->writeln(\sprintf('No subscriptions were found belonging to the organization %s.', $this->api()->getOrganizationLabel($organization)));
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

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $title = \sprintf('Subscriptions belonging to the organization <info>%s</info>', $this->api()->getOrganizationLabel($organization));
            if (isset($page)) {
                $title .= \sprintf(' (page %d)', $page);
            }
            $this->stdErr->writeln($title);
        }

        $table->render($rows, $headers, $defaultColumns);

        if (!$table->formatIsMachineReadable() && isset($collection['next'])) {
            $this->stdErr->writeln(\sprintf('More subscriptions are available on the next page (<info>--page %d</info>)', $nextPageNumber));
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
     * Use $options['query']['page'] to specify a page number explicitly.
     *
     * @todo move this into the API client library
     *
     * @param string $url
     * @param ClientInterface $client
     * @param array $options
     *
     * @return array{'items': Subscription[], 'next': ?string}
     */
    private function getPagedCollection($url, ClientInterface $client, array $options = [])
    {
        $request = $client->createRequest('get', $url, $options);
        $data = Subscription::send($request, $client);
        $items = Subscription::wrapCollection($data, $url, $client);

        $nextUrl = null;
        if (isset($data['_links']['next']['href'])) {
            $nextUrl = Url::fromString($url)->combine($data['_links']['next']['href'])->__toString();
        }

        return ['items' => $items, 'next' => $nextUrl];
    }
}
