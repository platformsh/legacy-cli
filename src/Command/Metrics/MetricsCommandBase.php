<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Model\Metrics\SourceField;
use Platformsh\Cli\Model\Metrics\SourceFieldPercentage;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Table;
use Symfony\Contracts\Service\Attribute\Required;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use Khill\Duration\Duration;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Model\Metrics\Query;
use Platformsh\Cli\Model\Metrics\TimeSpec;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class MetricsCommandBase extends CommandBase
{
    private Io $io;
    private PropertyFormatter $propertyFormatter;
    private Config $config;
    private Api $api;

    public const MIN_INTERVAL = 60; // 1 minute

    public const MIN_RANGE = 300; // 5 minutes
    public const DEFAULT_RANGE = 600;

    /**
     * @var bool whether services have been identified that use high memory
     */
    private bool $foundHighMemoryServices = false;

    public function __construct(
        protected readonly Selector $selector,
        protected readonly Table $table,
    ) {
        parent::__construct();
    }

    #[Required]
    public function autowire(Api $api, Config $config, Io $io, PropertyFormatter $propertyFormatter): void
    {
        $this->api = $api;
        $this->config = $config;
        $this->propertyFormatter = $propertyFormatter;
        $this->io = $io;
    }

    public function isEnabled(): bool
    {
        if (!$this->config->getBool('api.metrics')) {
            return false;
        }

        return parent::isEnabled();
    }

    protected function addMetricsOptions(): self
    {
        $duration = new Duration();
        $this->addOption(
            'range',
            'r',
            InputOption::VALUE_REQUIRED,
            'The time range. Metrics will be loaded for this duration until the end time (--to).'
            . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
            . "\n" . \sprintf(
                'Minimum <comment>%s</comment>, maximum <comment>8h</comment> or more (depending on the project), default <comment>%s</comment>.',
                $duration->humanize(self::MIN_RANGE),
                $duration->humanize(self::DEFAULT_RANGE),
            ),
        );
        // The $default is left at null so the lack of input can be detected.
        $this->addOption(
            'interval',
            'i',
            InputOption::VALUE_REQUIRED,
            'The time interval. Defaults to a division of the range.'
            . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
            . "\n" . \sprintf('Minimum <comment>%s</comment>.', $duration->humanize(self::MIN_INTERVAL)),
        );
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'The end time. Defaults to now.');
        $this->addOption('latest', '1', InputOption::VALUE_NONE, 'Show only the latest single data point');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by service or application name' . "\n" . Wildcard::HELP);
        $this->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by service type (if --service is not provided). The version is not required.' . "\n" . Wildcard::HELP);

        return $this;
    }

    /**
     * Returns the resources overview URL for the selected environment.
     *
     * @return string|false The link data or false on failure
     * @throws \GuzzleHttp\Exception\GuzzleException if there is an error in fetching observability metadata
     */
    private function getResourcesOverviewUrl(Environment $environment): false|string
    {
        if (!$environment->hasLink('#observability-pipeline')) {
            return false;
        }
        return rtrim($environment->getLink('#observability-pipeline'), '/') . '/resources/overview';
    }

    /**
     * @param InputInterface $input
     * @param array<String> $metricTypes
     * @param array<String> $metricAggs
     * @return array<mixed>
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function processQuery(InputInterface $input, array $metricTypes, array $metricAggs): array
    {
        // Common
        $timeSpec = $this->validateTimeInput($input);
        if (false === $timeSpec) {
            throw new \InvalidArgumentException('Invalid time input. Please check the --range, --to, and --interval options.');
        }

        // Common
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true, chooseEnvFilter: $this->getChooseEnvFilter()));
        $environment = $selection->getEnvironment();

        // Common
        if (!$this->table->formatIsMachineReadable()) {
            $this->selector->ensurePrintedSelection($selection);
        }

        if (!$link = $this->getResourcesOverviewUrl($environment)) {
            throw new \InvalidArgumentException('Observability API link not found for the environment.');
        }

        $query = Query::fromTimeSpec($timeSpec);

        $metricsQueryUrl = $link;

        $selectedServiceNames = $this->getServices($input, $environment);
        if (!empty($selectedServiceNames)) {
            $this->io->debug('Selected service(s): ' . implode(', ', $selectedServiceNames));
            $query->setServices($selectedServiceNames);
        }
        $this->io->debug('Selected type(s): ' . implode(', ', $metricTypes));
        $query->setTypes($metricTypes);
        $this->io->debug('Selected agg(s): ' . implode(', ', $metricAggs));
        $query->setAggs($metricAggs);

        if ($this->stdErr->isDebug()) {
            $this->io->debug('Metrics query: ' . json_encode($query->asArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Perform the metrics query.
        $client = $this->api->getHttpClient();
        $request = new Request('GET', $metricsQueryUrl . $query->asString());

        try {
            $result = $client->send($request);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($request, $e->getResponse(), $e);
        }

        // Decode the response.
        $content = $result->getBody()->__toString();
        $items = json_decode($content, true);

        if (empty($items)) {
            $this->stdErr->writeln('No data points found.');

            throw new \RuntimeException('No data points were found in the metrics response.');
        }

        // Filter to only the latest timestamp if --latest is given.
        if ($input->getOption('latest')) {
            foreach (array_reverse($items['data']) as $item) {
                if (isset($item['services'])) {
                    $items['data'] = [$item];
                    break;
                }
            }
        }

        // It's possible that there is nothing to display, e.g. if the router
        // has been filtered out and no metrics were available for the other
        // services, perhaps because the environment was paused.
        if (empty($items['data'])) {
            $this->stdErr->writeln('No values were found to display.');

            if ('paused' === $environment->status) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('The environment is currently paused.');
                $this->stdErr->writeln('Metrics collection will start when the environment is redeployed.');
            }

            throw new \RuntimeException('No data points were found in the metrics response.');
        }

        return [$items, $environment];
    }

    /** @return array<String> */
    private function getServices(InputInterface $input, Environment $environment): array
    {
        // Select services based on the --service or --type options.
        $deployment = $this->api->getCurrentDeployment($environment);
        $allServices = array_merge($deployment->webapps, $deployment->services, $deployment->workers);
        $servicesInput = ArrayArgument::getOption($input, 'service');
        $selectedServiceNames = [];
        if (!empty($servicesInput)) {
            $selectedServiceNames = Wildcard::select(array_merge(array_keys($allServices), ['router']), $servicesInput);
            if (!$selectedServiceNames) {
                $this->stdErr->writeln('No services were found matching the name(s): <error>' . implode(', ', $servicesInput) . '</error>');

                throw new \RuntimeException('No services were found matching the name(s): ' . implode(', ', $servicesInput));
            }
        } elseif ($typeInput = ArrayArgument::getOption($input, 'type')) {
            $byType = [];
            foreach ($allServices as $name => $service) {
                $type = $service->type;
                [$prefix] = explode(':', $service->type, 2);
                $byType[$type][] = $name;
                $byType[$prefix][] = $name;
            }
            $selectedKeys = Wildcard::select(array_merge(array_keys($byType), ['router']), $typeInput);
            if (!$selectedKeys) {
                $this->stdErr->writeln('No services were found matching the type(s): <error>' . implode(', ', $typeInput) . '</error>');

                throw new \RuntimeException('No services were found matching the type(s): ' . implode(', ', $typeInput));
            }
            foreach ($selectedKeys as $selectedKey) {
                $selectedServiceNames = array_merge($selectedServiceNames, $byType[$selectedKey]);
            }
            $selectedServiceNames = array_unique($selectedServiceNames);
        }

        return $selectedServiceNames;
    }

    protected function getChooseEnvFilter(): ?callable
    {
        return null;
    }

    /**
     * Validates the interval and range input, and finds defaults.
     *
     * Sets the startTime, endTime, and interval properties.
     *
     * @see self::startTime, self::$endTime, self::$interval
     */
    protected function validateTimeInput(InputInterface $input): false|TimeSpec
    {
        if ($to = $input->getOption('to')) {
            $endTime = \strtotime((string) $to);
            if (!$endTime) {
                $this->stdErr->writeln('Failed to parse --to time: ' . $to);

                return false;
            }
        } else {
            $endTime = time();
        }
        if ($rangeStr = $input->getOption('range')) {
            $rangeSeconds = (new Duration())->toSeconds($rangeStr);
            if (empty($rangeSeconds)) {
                $this->stdErr->writeln('Invalid --range: <error>' . $rangeStr . '</error>');

                return false;
            } elseif ($rangeSeconds < self::MIN_RANGE) {
                $this->stdErr->writeln(\sprintf('The --range <error>%s</error> is too short: it must be at least %d seconds (%s).', $rangeStr, self::MIN_RANGE, (new Duration())->humanize(self::MIN_RANGE)));

                return false;
            }
            $rangeSeconds = (int) $rangeSeconds;
        } else {
            $rangeSeconds = self::DEFAULT_RANGE;
        }

        $startTime = $endTime - $rangeSeconds;
        $interval = null;

        if ($intervalString = $input->getOption('interval')) {
            $interval = (int) (new Duration())->toSeconds($intervalString);

            if (empty($interval)) {
                $this->stdErr->writeln('Invalid --range: <error>' . $intervalString . '</error>');

                return false;
            }

            if ($interval > $endTime - $startTime) {
                $this->stdErr->writeln(\sprintf('The --interval <error>%s</error> is invalid. It cannot be greater than the selected time range', $intervalString));

                return false;
            }
        }

        return new TimeSpec($startTime, $endTime, $interval);
    }

    /**
     * @param array<mixed> $values
     * @param array<mixed> $fieldMapping
     * @param Environment $environment
     * @return array<mixed>
     * @throws \Exception
     */
    protected function buildRows(array $values, array $fieldMapping, Environment $environment): array
    {
        $sortServices = $this->getSortedServices($environment);
        $serviceTypes = [];

        $rows = [];
        $lastCountPerTimestamp = 0;
        foreach ($values['data'] as $point) {
            $timestamp = $point['timestamp'];

            if (!isset($point['services'])) {
                continue;
            }

            $byService = $point['services'];
            // Add a separator if there was more than one row for the previous timestamp.
            if ($lastCountPerTimestamp > 1) {
                $rows[] = new TableSeparator();
            }
            $startCount = count($rows);
            $formattedTimestamp = $this->propertyFormatter->formatDate($timestamp);

            uksort($byService, $sortServices);
            foreach ($byService as $service => $byDimension) {
                if (!isset($serviceTypes[$service])) {
                    $serviceTypes[$service] = $this->getServiceType($environment, $service);
                }

                $row = [];
                $row['timestamp'] = new AdaptiveTableCell($formattedTimestamp, ['wrap' => false]);
                $row['service'] = $service;
                $row['type'] = $this->propertyFormatter->format($serviceTypes[$service], 'service_type');
                foreach ($fieldMapping as $field => $fieldDefinition) {
                    /* @var Field $fieldDefinition */
                    $row[$field] = $fieldDefinition->format->format($this->getValueFromSource($byDimension, $fieldDefinition->value), $fieldDefinition->warn);
                }
                $rows[] = $row;
            }
            $lastCountPerTimestamp = count($rows) - $startCount;
        }

        return $rows;
    }

    private function getServiceType(Environment $environment, string $service): string
    {
        $deployment = $this->api->getCurrentDeployment($environment);

        if (isset($deployment->services[$service])) {
            $type = $deployment->services[$service]->type;
        } elseif (isset($deployment->webapps[$service])) {
            $type = $deployment->webapps[$service]->type;
        } elseif (isset($deployment->workers[$service])) {
            $type = $deployment->workers[$service]->type;
        } else {
            $type = '';
        }

        return $type;
    }

    private function getSortedServices(Environment $environment): \Closure
    {
        $deployment = $this->api->getCurrentDeployment($environment);

        // Create a closure which can sort services by name, putting apps and
        // workers first.
        $appAndWorkerNames = array_keys(array_merge($deployment->webapps, $deployment->workers));
        sort($appAndWorkerNames, SORT_NATURAL);
        $serviceNames = array_keys($deployment->services);
        sort($serviceNames, SORT_NATURAL);
        $nameOrder = array_flip(array_merge($appAndWorkerNames, $serviceNames, ['router']));
        $sortServices = function ($a, $b) use ($nameOrder): int {
            $aPos = $nameOrder[$a] ?? 1000;
            $bPos = $nameOrder[$b] ?? 1000;

            return $aPos > $bPos ? 1 : ($aPos < $bPos ? -1 : 0);
        };

        return $sortServices;
    }

    /**
     * @param array<mixed> $point
     * @param SourceField|SourceFieldPercentage $fieldDefinition
     * @return float|null
     */
    private function getValueFromSource(array $point, SourceField|SourceFieldPercentage $fieldDefinition): ?float
    {
        if ($fieldDefinition instanceof SourceFieldPercentage) {
            $value = $this->extractValue($point, $fieldDefinition->value);
            $limit = $this->extractValue($point, $fieldDefinition->limit);

            return $limit > 0 ? $value / $limit * 100 : null;
        }

        return $this->extractValue($point, $fieldDefinition);
    }

    /**
     * @param array<mixed> $point
     * @param SourceField $sourceField
     * @return float|null
     */
    private function extractValue(array $point, SourceField $sourceField): ?float
    {
        if (isset($sourceField->mountpoint)) {
            if (!isset($point['mountpoints'][$sourceField->mountpoint])) {
                return null;
            }
            if (!isset($point['mountpoints'][$sourceField->mountpoint][$sourceField->source->value])) {
                throw new \RuntimeException(\sprintf('Source "%s" not found in the mountpoint "%s".', $sourceField->source->value, $sourceField->mountpoint));
            }
            if (!isset($point['mountpoints'][$sourceField->mountpoint][$sourceField->source->value][$sourceField->aggregation->value])) {
                throw new \RuntimeException(\sprintf('Aggregation "%s" not found for source "%s" in mountpoint "%s".', $sourceField->aggregation->value, $sourceField->source->value, $sourceField->mountpoint));
            }

            return $point['mountpoints'][$sourceField->mountpoint][$sourceField->source->value][$sourceField->aggregation->value];
        }

        if (!isset($point[$sourceField->source->value])) {
            throw new \RuntimeException(\sprintf('Source "%s" not found in the data point.', $sourceField->source->value));
        }

        return $point[$sourceField->source->value][$sourceField->aggregation->value] ?? null;
    }

    /**
     * Shows an explanation if services were found that use high memory.
     */
    protected function explainHighMemoryServices(): void
    {
        if ($this->foundHighMemoryServices) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<comment>Note:</comment> it is possible for service memory usage to appear high even in normal circumstances.');
        }
    }
}
