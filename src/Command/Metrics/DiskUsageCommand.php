<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Model\Metrics\Aggregation;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Model\Metrics\Format;
use Platformsh\Cli\Model\Metrics\MetricKind;
use Platformsh\Cli\Model\Metrics\SourceField;
use Platformsh\Cli\Model\Metrics\SourceFieldPercentage;
use Platformsh\Cli\Selector\Selector;
use Khill\Duration\Duration;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:disk-usage', description: 'Show disk usage of an environment', aliases: ['disk'])]
class DiskUsageCommand extends MetricsCommandBase
{
    /** @var array<string, string> */
    private const TABLE_HEADER = [
        'timestamp' => 'Timestamp',
        'service' => 'Service',
        'type' => 'Type',
        'used' => 'Used',
        'limit' => 'Limit',
        'percent' => 'Used %',
        'iused' => 'Inodes used',
        'ilimit' => 'Inodes limit',
        'ipercent' => 'Inodes %',
        'tmp_used' => '/tmp used',
        'tmp_limit' => '/tmp limit',
        'tmp_percent' => '/tmp %',
        'tmp_iused' => '/tmp inodes used',
        'tmp_ilimit' => '/tmp inodes limit',
        'tmp_ipercent' => '/tmp inodes %',
    ];
    /** @var string[] */
    private array $defaultColumns = ['timestamp', 'service', 'used', 'limit', 'percent', 'ipercent', 'tmp_percent'];
    /** @var string[] */
    private array $tmpReportColumns = ['timestamp', 'service', 'tmp_used', 'tmp_limit', 'tmp_percent', 'tmp_ipercent'];

    public function __construct(
        private readonly PropertyFormatter $propertyFormatter,
        Selector $selector,
        Table $table
    ) {
        parent::__construct($selector, $table);
    }

    protected function configure(): void
    {
        $this->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('tmp', null, InputOption::VALUE_NONE, 'Report temporary disk usage (shows columns: ' . implode(', ', $this->tmpReportColumns) . ')');
        $this->addMetricsOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), self::TABLE_HEADER, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('tmp')) {
            $input->setOption('columns', $this->tmpReportColumns);
        }
        $this->table->removeDeprecatedColumns(['interval'], '', $input, $output);

        [$values, $environment] = $this->processQuery($input, [MetricKind::API_TYPE_DISK, MetricKind::API_TYPE_INODES], [MetricKind::API_AGG_AVG]);

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'used' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/mnt'),
            ),
            'limit' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/mnt'),
            ),
            'percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/mnt'),
                    new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/mnt')
                ),
            ),

            'iused' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/mnt'),
            ),
            'ilimit' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/mnt'),
            ),
            'ipercent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/mnt'),
                    new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/mnt')
                ),
            ),

            'tmp_used' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/tmp'),
            ),
            'tmp_limit' => new Field(
                $bytes ? Format::Rounded : Format::Disk,
                new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/tmp'),
            ),
            'tmp_percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DiskUsed, Aggregation::Avg, '/tmp'),
                    new SourceField(MetricKind::DiskLimit, Aggregation::Max, '/tmp')
                ),
            ),

            'tmp_iused' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesUsed, Aggregation::Avg, '/tmp'),
            ),
            'tmp_ilimit' => new Field(
                Format::Rounded,
                new SourceField(MetricKind::InodesLimit, Aggregation::Max, '/tmp'),
            ),
            'tmp_ipercent' => new Field(
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
                'Average %s at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                $input->getOption('tmp') ? 'temporary disk usage' : 'disk usage',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to']),
            ));
        }

        $this->table->render($rows, self::TABLE_HEADER, $this->defaultColumns);

        return 0;
    }
}
