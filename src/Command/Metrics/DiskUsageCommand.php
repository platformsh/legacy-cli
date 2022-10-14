<?php
namespace Platformsh\Cli\Command\Metrics;

use GuzzleHttp\Exception\BadResponseException;
use Khill\Duration\Duration;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\JsonLines;
use Platformsh\Client\Exception\ApiResponseException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiskUsageCommand extends CommandBase
{
    const RED_WARNING_THRESHOLD = 90; // percent
    const YELLOW_WARNING_THRESHOLD = 80; // percent

    const MIN_INTERVAL = 60; // 1 minute
    const MAX_INTERVAL = 3600; // 1 hour

    const MIN_RANGE = 300; // 5 minutes
    const DEFAULT_RANGE = 600;

    protected $stability = 'ALPHA';

    public function isEnabled()
    {
        if (!$this->config()->getWithDefault('api.metrics', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $duration = new Duration();

        $this->setName('metrics:disk-usage')
            ->setAliases(['disk'])
            ->setDescription('Show disk usage on a service')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'The service name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The service type (if the service name is not provided), e.g. mysql, pgsql, mongodb, etc. The type version is not required.')
            ->addOption('range', 'r', InputOption::VALUE_REQUIRED,
                'The time range. Metrics will be loaded for this duration until the end time (--to).'
                . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
                . "\n" . \sprintf(
                    'Minimum <comment>%s</comment>, maximum <comment>8h</comment> or more (depending on the project), default <comment>%s</comment>.',
                    $duration->humanize(self::MIN_RANGE),
                    $duration->humanize(self::DEFAULT_RANGE)
                )
            )
            // The $default is left at null so the lack of input can be detected.
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED,
                'The time interval. Defaults to a division of the range.'
                . "\n" . 'You can specify units: hours (h), minutes (m), or seconds (s).'
                . "\n" . \sprintf('Minimum <comment>%s</comment>, maximum <comment>%s</comment>.', $duration->humanize(self::MIN_INTERVAL), $duration->humanize(self::MAX_INTERVAL))
            )
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The end time. Defaults to now.')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('latest', '1', InputOption::VALUE_NONE, 'Show only the latest single data point');
        $this->addExample('Show the persistent disk usage of a mysql service in five-minute intervals over the last hour', '--type mysql -i 5m -r 1h');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $intervalSeconds = null;
        if ($intervalStr = $input->getOption('interval')) {
            $duration = new Duration();
            $intervalSeconds = $duration->toSeconds($intervalStr);
            if (empty($intervalSeconds)) {
                $this->stdErr->writeln('Invalid --interval: <error>' . $intervalStr . '</error>');
                return 1;
            } elseif ($intervalSeconds < self::MIN_INTERVAL) {
                $this->stdErr->writeln(\sprintf('The --interval <error>%s</error> is too short: it must be at least %d seconds.', $intervalStr, self::MIN_INTERVAL));
                return 1;
            } elseif ($intervalSeconds > self::MAX_INTERVAL) {
                $this->stdErr->writeln(\sprintf('The --interval <error>%s</error> is too long: it must be %d seconds or less.', $intervalStr, self::MAX_INTERVAL));
                return 1;
            }
            $intervalSeconds = \intval($intervalSeconds);
        }

        if ($to = $input->getOption('to')) {
            $endTime = \strtotime($to);
            if (!$endTime) {
                $this->stdErr->writeln('Failed to parse --to time: ' . $to);
                return 1;
            }
        } else {
            $endTime = time();
        }
        if ($rangeStr = $input->getOption('range')) {
            $rangeSeconds = (new Duration())->toSeconds($rangeStr);
            if (empty($rangeSeconds)) {
                $this->stdErr->writeln('Invalid --range: <error>' . $rangeStr . '</error>');
                return 1;
            } elseif ($rangeSeconds < self::MIN_RANGE) {
                $this->stdErr->writeln(\sprintf('The --range <error>%s</error> is too short: it must be at least %d seconds (%s).', $rangeStr, self::MIN_RANGE, (new Duration())->humanize(self::MIN_RANGE)));
                return 1;
            }
            $rangeSeconds = \intval($rangeSeconds);
        } else {
            $rangeSeconds = self::DEFAULT_RANGE;
        }

        if ($intervalSeconds === null) {
            $intervalSeconds = $rangeSeconds > self::MIN_INTERVAL * 10 ? $this->roundDuration($rangeSeconds / 10) : self::MIN_INTERVAL;
        }

        if ($input->getOption('latest')) {
            $rangeSeconds = $intervalSeconds;
        }

        $startTime = $endTime - $rangeSeconds;

        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        if (!$environment->isActive()) {
            $this->stdErr->writeln(\sprintf('The environment %s is not currently active.', $this->api()->getEnvironmentLabel($environment, 'error')));
            return 1;
        }

        $environmentData = $environment->getData();
        if (!isset($environmentData['_links']['#metrics'])) {
            $this->stdErr->writeln(\sprintf('The metrics API is not available on the environment: %s', $this->api()->getEnvironmentLabel($environment, 'error')));

            return 1;
        }
        if (!isset($environmentData['_links']['#metrics'][0]['href'], $environmentData['_links']['#metrics'][0]['collection'])) {
            $this->stdErr->writeln(\sprintf('Unable to find metrics URLs for the environment: %s', $this->api()->getEnvironmentLabel($environment, 'error')));

            return 1;
        }

        $metricsApiUrl = $environmentData['_links']['#metrics'][0]['href'] . '/v1/metrics/query';
        $metricsApiCollection = $environmentData['_links']['#metrics'][0]['collection'];

        $query = [
            'stream' => [
                'stream' => 'metrics',
                'collection' => $metricsApiCollection,
            ],
            'interval' => $intervalSeconds . 's',
            'fields' => [
                [
                    'name' => 'used',
                    'expr' => 'AVG(`disk.space.used`)',
                ],
                [
                    'name' => 'limit',
                    'expr' => 'AVG(`disk.space.limit`)',
                ],
                [
                    'name' => 'percent',
                    'expr' => 'AVG(`disk.space.used`/`disk.space.limit`)*100',
                ],
                [
                    'name' => 'iused',
                    'expr' => 'AVG(`disk.inodes.used`)',
                ],
                [
                    'name' => 'ilimit',
                    'expr' => 'AVG(`disk.inodes.limit`)',
                ],
                [
                    'name' => 'ipercent',
                    'expr' => 'AVG(`disk.inodes.used`/`disk.inodes.limit`)*100',
                ],
            ],
            'range' => [
                'from' => date('Y-m-d\TH:i:s.uP', $startTime),
                'to' => date('Y-m-d\TH:i:s.uP', $endTime),
            ],
        ];

        $deployment = $this->getSelectedEnvironment()->getCurrentDeployment();

        $services = \array_merge($deployment->webapps, $deployment->services);
        if (empty($services)) {
            $this->stdErr->writeln('No services found.');
            return 1;
        }

        $serviceName = $input->getOption('service');
        if (!$serviceName) {
            $type = $input->getOption('type');

            $choices = [];
            /** @var \Platformsh\Client\Model\Deployment\WebApp|\Platformsh\Client\Model\Deployment\Service $service */
            foreach ($services as $name => $service) {
                if ($type !== null && $service->type !== $type && \strpos($service->type, $type . ':') !== 0) {
                    continue;
                }
                if ($service->disk > 0) {
                    $choices[$name] = \sprintf('%s (type: %s)', $name, $service->type);
                }
            }
            if (empty($choices)) {
                if ($type !== null) {
                    $this->stdErr->writeln(\sprintf('No services found with type <error>%s</error> and persistent disk space configured.', $type));
                } else {
                    $this->stdErr->writeln('No services found with persistent disk space configured.');
                }
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To list services, run: <info>%s services</info>', $this->config()->get('application.executable')));
                return 1;
            }

            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $serviceName = $questionHelper->choose($choices, 'Enter a number to choose a service (<fg=cyan>-s</>):');
            if ($serviceName === null) {
                $this->stdErr->writeln('A <error>--service</error> is required (the name of an app or service).');
                return 1;
            }
        }
        if (!isset($services[$serviceName])) {
            $this->stdErr->writeln(\sprintf('Service not found: <error>%s</error>', $serviceName));
            return 1;
        }
        /** @var \Platformsh\Client\Model\Deployment\WebApp|\Platformsh\Client\Model\Deployment\Service $service */
        $service = $services[$serviceName];

        $query['filters'][] = ['key' => 'service', 'value' => $serviceName];

        // Show persistent disk usage only, i.e. from the /mnt mountpoint.
        // TODO can this be hardcoded?
        $query['filters'][] = ['key' => 'mountpoint', 'value' => '/mnt'];

        if ($this->stdErr->isDebug()) {
            $this->debug("Metrics query: \n" . \json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading metrics...');

        $client = $this->api()->getHttpClient();
        $request = $client->createRequest('POST', $metricsApiUrl, ['json' => $query]);
        try {
            $result = $client->send($request);
            $progress->done();
        } catch (BadResponseException $e) {
            $progress->done();
            throw ApiResponseException::create($request, $e->getResponse(), $e);
        }

        $content = $result->getBody()->__toString();
        $items = JsonLines::decode($content);
        if (empty($items)) {
            $this->stdErr->writeln('No data points found.');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('Project: ' . $this->api()->getProjectLabel($this->getSelectedProject()));
            $this->stdErr->writeln('Environment: ' . $this->api()->getEnvironmentLabel($this->getSelectedEnvironment()));
            $this->stdErr->writeln(\sprintf('Service or app: <info>%s</info> (type: <info>%s</info>)', $serviceName, $service->type));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf(
                'Average disk usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($intervalSeconds),
                \date('Y-m-d H:i:s', $startTime),
                \date('Y-m-d H:i:s', $endTime)
            ));
        }

        $header = ['timestamp' => 'Timestamp',
            'used' => 'Used',
            'limit' => 'Limit',
            'percent' => 'Used %',
            'iused' => 'Inodes used',
            'ilimit' => 'Inodes limit',
            'ipercent' => 'Inodes %',
            'interval' => 'Interval',
        ];
        $defaultColumns = ['timestamp', 'used', 'limit', 'percent', 'ipercent'];
        $rows = [];

        $bytes = $input->getOption('bytes');

        $valuesByTimestamp = [];
        foreach ($items as $item) {
            $time = $item['point']['timestamp'];
            foreach ($item['point']['values'] as $value) {
                $name = $value['info']['name'];
                $valuesByTimestamp[$time][$name] = $value;
            }
        }

        if ($input->getOption('latest')) {
            \ksort($valuesByTimestamp, SORT_NATURAL);
            $valuesByTimestamp = \array_slice($valuesByTimestamp, -1, null, true);
        }

        foreach ($valuesByTimestamp as $time => $values) {
            $row = [
                'timestamp' => $formatter->formatDate($time),
                'used' => '',
                'limit' => '',
                'percent' => '',
                'iused' => '',
                'ilimit' => '',
                'ipercent' => '',
                'interval' => $intervalSeconds,
            ];
            foreach (['', 'i'] as $prefix) {
                if (isset($values[$prefix . 'used']['value']['max'])) {
                    $value = $values[$prefix . 'used']['value']['max'];
                    $row[$prefix . 'used'] = $bytes || $prefix === 'i' ? $value : FormatterHelper::formatMemory($value);
                }
                if (isset($values[$prefix . 'limit']['value'])) {
                    if (isset($values[$prefix . 'limit']['value']['min'])) {
                        $value = $values[$prefix . 'limit']['value']['min'];
                        $row[$prefix . 'limit'] = $bytes || $prefix === 'i' ? $value : FormatterHelper::formatMemory($value);
                    }
                    // TODO sometimes the 'min' value is omitted, there is only a max
                    elseif (isset($values[$prefix . 'limit']['value']['max'])) {
                        $value = $values[$prefix . 'limit']['value']['max'];
                        $row[$prefix . 'limit'] = $bytes || $prefix === 'i' ? $value : FormatterHelper::formatMemory($value);
                    }
                }
                if (isset($values[$prefix . 'percent']['value']['sum'])) {
                    $value = $values[$prefix . 'percent']['value']['sum'];
                    if ($value >= self::RED_WARNING_THRESHOLD) {
                        $row[$prefix . 'percent'] = \sprintf('<options=bold;fg=red>%.1f%%</>', $value);
                    } elseif ($value >= self::YELLOW_WARNING_THRESHOLD) {
                        $row[$prefix . 'percent'] = \sprintf('<options=bold;fg=yellow>%.1f%%</>', $value);
                    } else {
                        $row[$prefix . 'percent'] = \sprintf('%.1f%%', $value);
                    }
                }
            }
            $rows[] = $row;
        }

        $table->render($rows, $header, $defaultColumns);

        return 0;
    }

    /**
     * @param int|float $seconds
     *
     * @return int
     */
    private function roundDuration($seconds)
    {
        $targets = [3600 * 24, 3600 * 6, 3600 * 3, 3600, 1800, 900, 600, 300, 60, 10];
        foreach ($targets as $target) {
            if ($seconds > $target) {
                return (int) \ceil($seconds - $seconds % $target);
            }
        }
        return (int) \ceil($seconds);
    }
}
