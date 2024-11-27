<?php

namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Symfony\Contracts\Service\Attribute\Required;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use Khill\Duration\Duration;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Model\Metrics\Query;
use Platformsh\Cli\Model\Metrics\Sketch;
use Platformsh\Cli\Model\Metrics\TimeSpec;
use Platformsh\Cli\Util\JsonLines;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class MetricsCommandBase extends CommandBase
{
    private readonly PropertyFormatter $propertyFormatter;
    private readonly Config $config;
    private readonly Api $api;
    const MIN_INTERVAL = 60; // 1 minute

    const MIN_RANGE = 300; // 5 minutes
    const DEFAULT_RANGE = 600;

    const MAX_INTERVALS = 100; // intervals per range

    /**
     * @var bool Whether services have been identified that use high memory.
     */
    private $foundHighMemoryServices = false;

    private $fields = [
        // Grid.
        'local' => [
            'cpu_used' => "AVG(SUM((`cpu.user` + `cpu.kernel`) / `interval`, 'service', 'instance'), 'service')",
            'cpu_percent' => "AVG(100 * SUM((`cpu.user` + `cpu.kernel`) / (`interval` * `cpu.cores`), 'service', 'instance'), 'service')",
            'cpu_limit' => "SUM(`cpu.cores`, 'service')",

            'mem_used' => "AVG(SUM(`memory.apps` + `memory.kernel` + `memory.buffers`, 'service', 'instance'), 'service')",
            'mem_percent' => "AVG(100 * SUM((`memory.apps` + `memory.kernel` + `memory.buffers`) / `memory.limit`, 'service', 'instance'), 'service')",
            'mem_limit' => "AVG(`memory.limit`, 'service')",

            'disk_used' => "AVG(`disk.space.used`, 'mountpoint', 'service')",
            'inodes_used' => "AVG(`disk.inodes.used`, 'mountpoint', 'service')",
            'disk_percent' => "AVG((`disk.space.used`/`disk.space.limit`)*100, 'mountpoint', 'service')",
            'inodes_percent' => "AVG((`disk.inodes.used`/`disk.inodes.limit`)*100, 'mountpoint', 'service')",
            'disk_limit' => "AVG(`disk.space.limit`, 'mountpoint', 'service')",
            'inodes_limit' => "AVG(`disk.inodes.limit`, 'mountpoint', 'service')",
        ],
        // Dedicated Generation 3 (DG3).
        'dedicated' => [
            'cpu_used' => "AVG(SUM((`cpu.user` + `cpu.kernel`) / `interval`, 'hostname', 'service', 'instance'), 'service')",
            'cpu_percent' => "AVG(100 * SUM((`cpu.user` + `cpu.kernel`) / (`interval` * `cpu.cores`), 'hostname', 'service', 'instance'), 'service')",
            'cpu_limit' => "AVG(`cpu.cores`, 'service')",

            'disk_used' => "AVG(`disk.space.used`, 'mountpoint', 'service')",
            'inodes_used' => "AVG(`disk.inodes.used`, 'mountpoint', 'service')",
            'disk_percent' => "AVG((`disk.space.used`/`disk.space.limit`)*100, 'mountpoint', 'service')",
            'inodes_percent' => "AVG((`disk.inodes.used`/`disk.inodes.limit`)*100, 'mountpoint', 'service')",
            'disk_limit' => "AVG(`disk.space.limit`, 'mountpoint', 'service')",
            'inodes_limit' => "AVG(`disk.inodes.limit`, 'mountpoint', 'service')",

            'mem_used' => "AVG(SUM(`memory.apps` + `memory.kernel` + `memory.buffers`, 'hostname', 'service', 'instance'), 'service')",
            'mem_percent' => "AVG(SUM(100 * (`memory.apps` + `memory.kernel` + `memory.buffers`) / `memory.limit`, 'hostname', 'service', 'instance'), 'service')",
            'mem_limit' => "AVG(`memory.limit`, 'service')",
        ],
        // Dedicated Generation 2 (DG2), formerly known as "Enterprise".
        'enterprise' => [
            'cpu_used' => "AVG(SUM((`cpu.user` + `cpu.kernel`) / `interval`, 'hostname'))",
            'cpu_percent' => "AVG(100 * SUM((`cpu.user` + `cpu.kernel`) / (`interval` * `cpu.cores`), 'hostname'))",
            'cpu_limit' => "AVG(`cpu.cores`, 'service')",

            'mem_used' => "AVG(SUM(`memory.apps` + `memory.kernel` + `memory.buffers`, 'hostname'))",
            'mem_percent' => "AVG(SUM(100 * (`memory.apps` + `memory.kernel` + `memory.buffers`) / `memory.limit`, 'hostname'))",
            'mem_limit' => "AVG(`memory.limit`, 'service')",

            'disk_used' => "AVG(`disk.space.used`, 'mountpoint')",
            'inodes_used' => "AVG(`disk.inodes.used`, 'mountpoint')",
            'disk_percent' => "AVG((`disk.space.used`/`disk.space.limit`)*100, 'mountpoint')",
            'inodes_percent' => "AVG((`disk.inodes.used`/`disk.inodes.limit`)*100, 'mountpoint')",
            'disk_limit' => "AVG(`disk.space.limit`, 'mountpoint')",
            'inodes_limit' => "AVG(`disk.inodes.limit`, 'mountpoint')",
        ],
    ];
    #[Required]
    public function autowire(Api $api, Config $config, PropertyFormatter $propertyFormatter) : void
    {
        $this->api = $api;
        $this->config = $config;
        $this->propertyFormatter = $propertyFormatter;
    }

    public function isEnabled(): bool
    {
        if (!$this->config->getWithDefault('api.metrics', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function addMetricsOptions()
    {
        $duration = new Duration();
        $this->addOption('range', 'r', InputOption::VALUE_REQUIRED,
            'The time range. Metrics will be loaded for this duration until the end time (--to).'
            . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
            . "\n" . \sprintf(
                'Minimum <comment>%s</comment>, maximum <comment>8h</comment> or more (depending on the project), default <comment>%s</comment>.',
                $duration->humanize(self::MIN_RANGE),
                $duration->humanize(self::DEFAULT_RANGE)
            )
        );
        // The $default is left at null so the lack of input can be detected.
        $this->addOption('interval', 'i', InputOption::VALUE_REQUIRED,
            'The time interval. Defaults to a division of the range.'
            . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
            . "\n" . \sprintf('Minimum <comment>%s</comment>.', $duration->humanize(self::MIN_INTERVAL))
        );
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'The end time. Defaults to now.');
        $this->addOption('latest', '1', InputOption::VALUE_NONE, 'Show only the latest single data point');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by service or application name' . "\n" . Wildcard::HELP);
        $this->addOption('type', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter by service type (if --service is not provided). The version is not required.' . "\n" . Wildcard::HELP);
        return $this;
    }

    /**
     * Returns the metrics URL and collection information for the selected environment.
     *
     * @return array{'href': string, 'collection': string}|false
     *   The link data or false on failure.
     */
    protected function getMetricsLink(Environment $environment)
    {
        $environmentData = $environment->getData();
        if (!isset($environmentData['_links']['#metrics'])) {
            $this->stdErr->writeln(\sprintf('The metrics API is not currently available on the environment: %s', $this->api->getEnvironmentLabel($environment, 'error')));

            return false;
        }
        if (!isset($environmentData['_links']['#metrics'][0]['href'], $environmentData['_links']['#metrics'][0]['collection'])) {
            $this->stdErr->writeln(\sprintf('Unable to find metrics URLs for the environment: %s', $this->api->getEnvironmentLabel($environment, 'error')));

            return false;
        }

        return $environmentData['_links']['#metrics'][0];
    }

    /**
     * Splits a dimension string into fields.
     *
     * @param string $dimension
     * @return array<string, string>
     */
    private function dimensionFields($dimension)
    {
        $fields = ['service' => '', 'mountpoint' => '', 'instance' => ''];
        foreach (explode('/', $dimension) as $field) {
            $parts = explode('=', $field, 2);
            if (count($parts) === 2) {
                $fields[urldecode($parts[0])] = urldecode($parts[1]);
            }
        }
        return $fields;
    }

    /**
     * Validates input and fetches metrics.
     *
     * @param InputInterface $input
     * @param TimeSpec $timeSpec
     * @param Environment $environment
     * @param string[] $fieldNames
     *   An array of field names, which map to queries in $this->fields.
     *
     * @return false|array
     *   False on failure, or an array of sketch values, keyed by: time, service, dimension, and name.
     */
    protected function fetchMetrics(InputInterface $input, TimeSpec $timeSpec, Environment $environment, $fieldNames)
    {
        $link = $this->getMetricsLink($environment);
        if (!$link) {
            return false;
        }

        $query = (new Query())
            ->setStartTime($timeSpec->getStartTime())
            ->setEndTime($timeSpec->getEndTime())
            ->setInterval($timeSpec->getInterval());

        $metricsQueryUrl = $link['href'] . '/v1/metrics/query';
        $query->setCollection($link['collection']);

        $deploymentType = $this->getDeploymentType($environment);
        if (!isset($this->fields[$deploymentType])) {
            if (($fallback = key($this->fields)) === false) {
                throw new \InvalidArgumentException('No query fields are defined');
            }
            $this->stdErr->writeln(sprintf(
                'No query fields are defined for the deployment type: <comment>%s</comment>. Falling back to: <comment>%s</comment>',
                $deploymentType,
                $fallback
            ));
            $deploymentType = $fallback;
        }

        // Add fields and expressions to the query based on the requested $fieldNames.
        $fieldNames = array_map(function ($f) {
            if (substr($f, 0, 4) === 'tmp_') {
                return substr($f, 4);
            }
            return $f;
        }, $fieldNames);
        foreach ($this->fields[$deploymentType] as $name => $expression) {
            if (in_array($name, $fieldNames)) {
                $query->addField($name, $expression);
            }
        }

        // Select services based on the --service or --type options.
        $deployment = $this->api->getCurrentDeployment($environment);
        $allServices = array_merge($deployment->webapps, $deployment->services, $deployment->workers);
        $servicesInput = ArrayArgument::getOption($input, 'service');
        $selectedServiceNames = [];
        if (!empty($servicesInput)) {
            $selectedServiceNames = Wildcard::select(array_merge(array_keys($allServices), ['router']), $servicesInput);
            if (!$selectedServiceNames) {
                $this->stdErr->writeln('No services were found matching the name(s): <error>' . implode(', ', $servicesInput) . '</error>');
                return false;
            }
        } elseif ($typeInput = ArrayArgument::getOption($input, 'type')) {
            $byType = [];
            foreach ($allServices as $name => $service) {
                $type = $service->type;
                list($prefix) = explode(':', $service->type, 2);
                $byType[$type][] = $name;
                $byType[$prefix][] = $name;
            }
            $selectedKeys = Wildcard::select(array_merge(array_keys($byType), ['router']), $typeInput);
            if (!$selectedKeys) {
                $this->stdErr->writeln('No services were found matching the type(s): <error>' . implode(', ', $typeInput) . '</error>');
                return false;
            }
            foreach ($selectedKeys as $selectedKey) {
                $selectedServiceNames = array_merge($selectedServiceNames, $byType[$selectedKey]);
            }
            $selectedServiceNames = array_unique($selectedServiceNames);
        }
        if (!empty($selectedServiceNames)) {
            $this->debug('Selected service(s): ' . implode(', ', $selectedServiceNames));
            if (count($selectedServiceNames) === 1) {
                $query->addFilter('service', reset($selectedServiceNames));
            }
        }

        if ($this->stdErr->isDebug()) {
            $this->debug('Metrics query: ' . json_encode($query->asArray(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }

        // Perform the metrics query.
        $client = $this->api->getHttpClient();
        $request = new Request('POST', $metricsQueryUrl, [
            'Content-Type' => 'application/json',
        ], json_encode($query->asArray()));
        try {
            $result = $client->send($request);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($request, $e->getResponse(), $e);
        }

        // Decode the response.
        $content = $result->getBody()->__toString();
        $items = JsonLines::decode($content);
        if (empty($items)) {
            $this->stdErr->writeln('No data points found.');
            return false;
        }

        // Group the returned values by time, service, dimension, and field name.
        // Filter by the selected services.
        $values = [];
        foreach ($items as $item) {
            $time = $item['point']['timestamp'];
            $dimension = isset($item['point']['dimension']) ? $item['point']['dimension'] : '';
            $dimensionFields = $this->dimensionFields($dimension);
            $service = $dimensionFields['service'];
            // Skip the router service by default (if no services are selected).
            if (empty($servicesInput) && $service === 'router') {
                continue;
            }
            if (!empty($selectedServiceNames) && !in_array($service, $selectedServiceNames, true)) {
                continue;
            }
            $fieldPrefix = $dimensionFields['mountpoint'] === '/tmp' ? 'tmp_' : '';
            foreach ($item['point']['values'] as $value) {
                $name = $value['info']['name'];
                if (isset($values[$time][$service][$dimension][$fieldPrefix . $name])) {
                    $this->stdErr->writeln(\sprintf(
                        '<comment>Warning:</comment> duplicate value found for time %s, service %s, dimension %s, field %s',
                        $time, $service, $dimension, $fieldPrefix . $name
                    ));
                } else {
                    $values[$time][$service][$dimension][$fieldPrefix . $name] = Sketch::fromApiValue($value);
                }
            }
        }

        // Filter to only the latest timestamp if --latest is given.
        if ($input->getOption('latest')) {
            \ksort($values, SORT_NATURAL);
            $values = \array_slice($values, -1, null, true);
        }

        // It's possible that there is nothing to display, e.g. if the router
        // has been filtered out and no metrics were available for the other
        // services, perhaps because the environment was paused.
        if (empty($values)) {
            $this->stdErr->writeln('No values were found to display.');

            if ($environment->status === 'paused') {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('The environment is currently paused.');
                $this->stdErr->writeln('Metrics collection will start when the environment is redeployed.');
            }

            return false;
        }

        return $values;
    }

    /**
     * Validates the interval and range input, and finds defaults.
     *
     * Sets the startTime, endTime, and interval properties.
     *
     * @see self::startTime, self::$endTime, self::$interval
     *
     * @param InputInterface $input
     *
     * @return TimeSpec|false
     */
    protected function validateTimeInput(InputInterface $input)
    {
        $interval = null;
        if ($intervalStr = $input->getOption('interval')) {
            $duration = new Duration();
            $interval = $duration->toSeconds($intervalStr);
            if (empty($interval)) {
                $this->stdErr->writeln('Invalid --interval: <error>' . $intervalStr . '</error>');
                return false;
            } elseif ($interval < self::MIN_INTERVAL) {
                $this->stdErr->writeln(\sprintf('The --interval <error>%s</error> is too short: it must be at least %d seconds.', $intervalStr, self::MIN_INTERVAL));
                return false;
            }
            $interval = \intval($interval);
        }

        if ($to = $input->getOption('to')) {
            $endTime = \strtotime($to);
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
            $rangeSeconds = \intval($rangeSeconds);
        } else {
            $rangeSeconds = self::DEFAULT_RANGE;
        }

        if ($interval === null) {
            $interval = $this->defaultInterval($rangeSeconds);
        } elseif ($interval > 0 && ($rangeSeconds / $interval) > self::MAX_INTERVALS) {
            $this->stdErr->writeln(\sprintf(
                'The --interval <error>%s</error> is too short relative to the --range (<error>%s</error>): the maximum number of intervals is <error>%d</error>.',
                (new Duration())->humanize($interval),
                (new Duration())->humanize($rangeSeconds),
                self::MAX_INTERVALS
            ));
            return false;
        }

        if ($input->getOption('latest')) {
            $rangeSeconds = $interval;
        }

        $startTime = $endTime - $rangeSeconds;

        return new TimeSpec($startTime, $endTime, $interval);
    }

    /**
     * Determines a default interval based on the range.
     *
     * @param int $range The range in seconds.
     *
     * @return int
     */
    private function defaultInterval($range)
    {
        $divisor = 5; // Number of points per time range.
        // Number of seconds to round to:
        $granularity = 10;
        foreach ([3600*24, 3600*6, 3600*3, 3600, 600, 300, 60, 30] as $level) {
            if ($range >= $level * $divisor) {
                $granularity = $level;
                break;
            }
        }
        $interval = \round($range / ($divisor * $granularity)) * $granularity;
        if ($interval <= self::MIN_INTERVAL) {
            return self::MIN_INTERVAL;
        }

        return (int) $interval;
    }

    /**
     * Returns the deployment type of an environment (needed for differing queries).
     *
     * @param Environment $environment
     * @return string
     */
    private function getDeploymentType(Environment $environment)
    {
        if (in_array($environment->deployment_target, ['local', 'enterprise', 'dedicated'])) {
            return $environment->deployment_target;
        }
        $data = $environment->getData();
        if (isset($data['_embedded']['deployments'][0]['type'])) {
            return $data['_embedded']['deployments'][0]['type'];
        }
        throw new \RuntimeException('Failed to determine the deployment type');
    }

    /**
     * Builds metrics table rows.
     *
     * @param array $values
     *   An array of values from fetchMetrics().
     * @param array<string, Field> $fields
     *   An array of fields keyed by column name.
     *
     * @return array
     *   Table rows.
     */
    protected function buildRows(array $values, $fields)
    {
        $formatter = $this->propertyFormatter;

        $deployment = $this->api->getCurrentDeployment($this->getSelectedEnvironment());

        // Create a closure which can sort services by name, putting apps and
        // workers first.
        $appAndWorkerNames = array_keys(array_merge($deployment->webapps, $deployment->workers));
        sort($appAndWorkerNames, SORT_NATURAL);
        $serviceNames = array_keys($deployment->services);
        sort($serviceNames, SORT_NATURAL);
        $nameOrder = array_flip(array_merge($appAndWorkerNames, $serviceNames, ['router']));
        $sortServices = function ($a, $b) use ($nameOrder) {
            $aPos = isset($nameOrder[$a]) ? $nameOrder[$a] : 1000;
            $bPos = isset($nameOrder[$b]) ? $nameOrder[$b] : 1000;
            return $aPos > $bPos ? 1 : ($aPos < $bPos ? -1 : 0);
        };

        $rows = [];
        $lastCountPerTimestamp = 0;
        foreach ($values as $timestamp => $byService) {
            // Add a separator if there was more than one row for the previous timestamp.
            if ($lastCountPerTimestamp > 1) {
                $rows[] = new TableSeparator();
            }
            $startCount = count($rows);
            $formattedTimestamp = $formatter->formatDate($timestamp);
            uksort($byService, $sortServices);
            foreach ($byService as $service => $byDimension) {
                if (isset($deployment->services[$service])) {
                    $type = $deployment->services[$service]->type;
                } elseif (isset($deployment->webapps[$service])) {
                    $type = $deployment->webapps[$service]->type;
                } elseif (isset($deployment->workers[$service])) {
                    $type = $deployment->workers[$service]->type;
                } else {
                    $type = '';
                }

                $serviceRows = [];
                foreach ($byDimension as $values) {
                    $row = [];
                    $row['timestamp'] = new AdaptiveTableCell($formattedTimestamp, ['wrap' => false]);
                    $row['service'] = $service;
                    $row['type'] = $formatter->format($type, 'service_type');
                    foreach ($fields as $columnName => $field) {
                        /** @var Field $field */
                        $fieldName = $field->getName();
                        if (isset($values[$fieldName])) {
                            /** @var Sketch $value */
                            $value = $values[$fieldName];
                            if ($fieldName === 'mem_percent' && isset($deployment->services[$service])) {
                                if ($value->average() > 90) {
                                    $this->foundHighMemoryServices = true;
                                }
                                $row[$columnName] = $field->format($values[$fieldName], false);
                            } elseif ($fieldName === 'mem_limit' && $service === 'router' && $value->average() == 0) {
                                $row[$columnName] = '';
                            } elseif ($fieldName === 'mem_percent' && $service === 'router' && $value->isInfinite()) {
                                $row[$columnName] = '';
                            } else {
                                $row[$columnName] = $field->format($values[$fieldName]);
                            }
                        }
                    }
                    $serviceRows[] = $row;
                }
                $rows = array_merge($rows, $this->mergeRows($serviceRows));
            }
            $lastCountPerTimestamp = count($rows) - $startCount;
        }
        return $rows;
    }

    /**
     * Merges table rows per service to reduce unnecessary empty cells.
     *
     * @param array $rows
     * @return array
     */
    private function mergeRows(array $rows)
    {
        $infoKeys = array_flip(['service', 'timestamp', 'instance', 'type']);
        $previous = $previousKey = null;
        foreach (array_keys($rows) as $key) {
            // Merge rows if they do not have any keys in common except for
            // $infoKeys, and if their values are the same for those keys.
            if ($previous !== null
                && !array_intersect_key(array_diff_key($rows[$key], $infoKeys), array_diff_key($previous, $infoKeys))
                && array_intersect_key($rows[$key], $infoKeys) == array_intersect_key($previous, $infoKeys)) {
                $rows[$key] += $previous;
                unset($rows[$previousKey]);
            }
            $previous = $rows[$key];
            $previousKey = $key;
        }
        return $rows;
    }

    /**
     * Displays the current project and environment, if not already displayed.
     *
     * @return void
     */
    protected function displayEnvironmentHeader()
    {
        if (!$this->printedSelectedEnvironment) {
            $this->stdErr->writeln('Selected project: ' . $this->api->getProjectLabel($this->getSelectedProject()));
            $this->stdErr->writeln('Selected environment: ' . $this->api->getEnvironmentLabel($this->getSelectedEnvironment()));
        }
        $this->stdErr->writeln('');
    }

    /**
     * Shows an explanation if services were found that use high memory.
     *
     * @return void
     */
    protected function explainHighMemoryServices()
    {
        if ($this->foundHighMemoryServices) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<comment>Note:</comment> it is possible for service memory usage to appear high even in normal circumstances.');
        }
    }
}
