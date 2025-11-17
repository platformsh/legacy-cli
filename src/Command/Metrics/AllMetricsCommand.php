<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Model\Metrics\Aggregation;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Model\Metrics\Format;
use Platformsh\Cli\Model\Metrics\MetricKind;
use Platformsh\Cli\Model\Metrics\SourceField;
use Platformsh\Cli\Model\Metrics\SourceFieldPercentage;
use Khill\Duration\Duration;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AllMetricsCommand extends MetricsCommandBase
{
    /**
     * @var array
     */
    private static $tableHeader = [
        'timestamp' => 'Timestamp',
        'service' => 'Service',
        'type' => 'Type',

        'cpu_used' => 'CPU used',
        'cpu_limit' => 'CPU limit',
        'cpu_percent' => 'CPU %',

        'mem_used' => 'Memory used',
        'mem_limit' => 'Memory limit',
        'mem_percent' => 'Memory %',

        'disk_used' => 'Disk used',
        'disk_limit' => 'Disk limit',
        'disk_percent' => 'Disk %',

        'inodes_used' => 'Inodes used',
        'inodes_limit' => 'Inodes limit',
        'inodes_percent' => 'Inodes %',

        'tmp_disk_used' => '/tmp used',
        'tmp_disk_limit' => '/tmp limit',
        'tmp_disk_percent' => '/tmp %',

        'tmp_inodes_used' => '/tmp inodes used',
        'tmp_inodes_limit' => '/tmp inodes limit',
        'tmp_inodes_percent' => '/tmp inodes %',
    ];

    /**
     * @var array
     */
    private $defaultColumns = [
        'timestamp',
        'service',

        'cpu_percent',
        'mem_percent',
        'disk_percent',
        'inodes_percent',

        'tmp_disk_percent',
        'tmp_inodes_percent',
    ];


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('metrics:all')
            ->setAliases(['metrics', 'met'])
            ->setDescription('Show CPU, disk and memory metrics for an environment')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
        $this->addExample('Show metrics for the last ' . (new Duration())->humanize(self::DEFAULT_RANGE));
        $this->addExample('Show metrics over the last hour', ' -r 1h');
        $this->addExample('Show metrics for all SQL services', '--type mariadb,%sql');
        $this->addMetricsOptions()->addProjectOption()->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), self::$tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = $this->processQuery($input, [
            MetricKind::API_TYPE_CPU,
            MetricKind::API_TYPE_DISK,
            MetricKind::API_TYPE_MEMORY,
            MetricKind::API_TYPE_INODES,
        ], [MetricKind::API_AGG_AVG]);

        $values = $result[0];
        $environment = $result[1];

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'cpu_used' => new Field(
                Format::ROUNDED_2P,
                new SourceField(MetricKind::CPU_USED, Aggregation::AVG)
            ),
            'cpu_limit' => new Field(
                Format::ROUNDED_2P,
                new SourceField(MetricKind::CPU_LIMIT, Aggregation::MAX)
            ),
            'cpu_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::CPU_USED, Aggregation::AVG),
                    new SourceField(MetricKind::CPU_LIMIT, Aggregation::MAX)
                )
            ),

            'mem_used' => new Field(
                $bytes ? Format::ROUNDED : Format::MEMORY,
                new SourceField(MetricKind::MEMORY_USED, Aggregation::AVG)
            ),
            'mem_limit' => new Field(
                $bytes ? Format::ROUNDED : Format::MEMORY,
                new SourceField(MetricKind::MEMORY_LIMIT, Aggregation::MAX)
            ),
            'mem_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::MEMORY_USED, Aggregation::AVG),
                    new SourceField(MetricKind::MEMORY_LIMIT, Aggregation::MAX)
                ),
                false
            ),

            'disk_used' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/mnt')
            ),
            'disk_limit' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/mnt')
            ),
            'disk_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/mnt'),
                    new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/mnt')
                )
            ),

            'tmp_disk_used' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/tmp')
            ),
            'tmp_disk_limit' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/tmp')
            ),
            'tmp_disk_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/tmp'),
                    new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/tmp')
                )
            ),

            'inodes_used' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/mnt')
            ),
            'inodes_limit' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/mnt')
            ),
            'inodes_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/mnt'),
                    new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/mnt')
                )
            ),

            'tmp_inodes_used' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/tmp')
            ),
            'tmp_inodes_limit' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/tmp')
            ),
            'tmp_inodes_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/tmp'),
                    new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/tmp')
                )
            ),
        ], $environment);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Metrics at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to'])
            ));
        }

        $table->render($rows, self::$tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You can run the <info>cpu</info>, <info>disk</info> and <info>mem</info> commands for more detail.');
        }

        return 0;
    }
}
