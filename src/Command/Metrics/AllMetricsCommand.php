<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Model\Metrics\Aggregation;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Model\Metrics\Format;
use Platformsh\Cli\Model\Metrics\MetricKind;
use Platformsh\Cli\Model\Metrics\SourceField;
use Platformsh\Cli\Model\Metrics\SourceFieldPercentage;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Khill\Duration\Duration;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:all', description: 'Show CPU, disk and memory metrics for an environment', aliases: ['metrics', 'met'])]
class AllMetricsCommand extends MetricsCommandBase
{
    /** @var array<string, string> */
    private const TABLE_HEADER = [
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

    /** @var string[] */
    private array $defaultColumns = [
        'timestamp',
        'service',

        'cpu_percent',
        'mem_percent',
        'disk_percent',
        'inodes_percent',

        'tmp_disk_percent',
        'tmp_inodes_percent',
    ];

    public function __construct(
        private readonly PropertyFormatter $propertyFormatter,
        Selector $selector,
        Table $table
    ) {
        parent::__construct($selector, $table);
    }

    protected function configure(): void
    {
        $this->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
        $this->addExample('Show metrics for the last ' . (new Duration())->humanize(self::DEFAULT_RANGE));
        $this->addExample('Show metrics over the last hour', ' -r 1h');
        $this->addExample('Show metrics for all SQL services', '--type mariadb,%sql');
        $this->addMetricsOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), self::TABLE_HEADER, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function getChooseEnvFilter(): ?callable
    {
        return SelectorConfig::filterEnvsMaybeActive();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$values, $environment] = $this->processQuery($input, [
            MetricKind::API_TYPE_CPU,
            MetricKind::API_TYPE_DISK,
            MetricKind::API_TYPE_MEMORY,
            MetricKind::API_TYPE_INODES,
        ], [MetricKind::API_AGG_AVG]);

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'cpu_used' => new Field(
                Format::Rounded2p,
                new SourceField(MetricKind::CpuUsed, Aggregation::Avg),
            ),
            'cpu_limit' => new Field(
                Format::Rounded2p,
                new SourceField(MetricKind::CpuLimit, Aggregation::Max),
            ),
            'cpu_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::CpuUsed, Aggregation::Avg),
                    new SourceField(MetricKind::CpuLimit, Aggregation::Max)
                ),
            ),

            'mem_used' => new Field(
                $bytes ? Format::Rounded : Format::Memory,
                new SourceField(MetricKind::MemoryUsed, Aggregation::Avg),
            ),
            'mem_limit' => new Field(
                $bytes ? Format::Rounded : Format::Memory,
                new SourceField(MetricKind::MemoryLimit, Aggregation::Max),
            ),
            'mem_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::MemoryUsed, Aggregation::Avg),
                    new SourceField(MetricKind::MemoryLimit, Aggregation::Max)
                ),
                false,
            ),

            'disk_used' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/mnt'),
            ),
            'disk_limit' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/mnt'),
            ),
            'disk_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/mnt'),
                    new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/mnt')
                ),
            ),

            'tmp_disk_used' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/tmp'),
            ),
            'tmp_disk_limit' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/tmp'),
            ),
            'tmp_disk_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/tmp'),
                    new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/tmp')
                ),
            ),

            'inodes_used' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/mnt'),
            ),
            'inodes_limit' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/mnt'),
            ),
            'inodes_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/mnt'),
                    new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/mnt')
                ),
            ),

            'tmp_inodes_used' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/tmp'),
            ),
            'tmp_inodes_limit' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/tmp'),
            ),
            'tmp_inodes_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/tmp'),
                    new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/tmp')
                ),
            ),
        ], $environment);

        if (!$this->table->formatIsMachineReadable()) {
            $formatter = $this->propertyFormatter;
            $this->stdErr->writeln(\sprintf(
                'Metrics at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to']),
            ));
        }

        $this->table->render($rows, self::TABLE_HEADER, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You can run the <info>cpu</info>, <info>disk</info> and <info>mem</info> commands for more detail.');
        }

        return 0;
    }
}
