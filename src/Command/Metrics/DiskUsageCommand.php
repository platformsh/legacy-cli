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

class DiskUsageCommand extends MetricsCommandBase
{
    /**
     * @var array
     */
    private static $tableHeader = array(
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
    );

    /**
     * @var array
     */
    private $defaultColumns = array('timestamp', 'service', 'used', 'limit', 'percent', 'ipercent', 'tmp_percent');

    /**
     * @var array
     */
    private $tmpReportColumns = array('timestamp', 'service', 'tmp_used', 'tmp_limit', 'tmp_percent', 'tmp_ipercent');

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('metrics:disk-usage')
            ->setAliases(array('disk'))
            ->setDescription('Show disk usage of an environment')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('tmp', null, InputOption::VALUE_NONE, 'Report temporary disk usage (shows columns: ' . implode(', ', $this->tmpReportColumns) . ')');
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
        if ($input->getOption('tmp')) {
            $input->setOption('columns', $this->tmpReportColumns);
        }

        $result = $this->processQuery($input, array(MetricKind::API_TYPE_DISK, MetricKind::API_TYPE_INODES), array(MetricKind::API_AGG_AVG));

        $values = $result[0];
        $environment = $result[1];

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, array(
            'used' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/mnt')
            ),
            'limit' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/mnt')
            ),
            'percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/mnt'),
                    new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/mnt')
                )
            ),

            'iused' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/mnt')
            ),
            'ilimit' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/mnt')
            ),
            'ipercent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/mnt'),
                    new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/mnt')
                )
            ),

            'tmp_used' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/tmp')
            ),
            'tmp_limit' => new Field(
                $bytes ? Format::ROUNDED : Format::DISK,
                new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/tmp')
            ),
            'tmp_percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::DISK_USED, Aggregation::AVG, '/tmp'),
                    new SourceField(MetricKind::DISK_LIMIT, Aggregation::MAX, '/tmp')
                )
            ),

            'tmp_iused' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/tmp')
            ),
            'tmp_ilimit' => new Field(
                Format::ROUNDED,
                new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/tmp')
            ),
            'tmp_ipercent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::INODES_USED, Aggregation::AVG, '/tmp'),
                    new SourceField(MetricKind::INODES_LIMIT, Aggregation::MAX, '/tmp')
                )
            ),
        ), $environment);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Average %s at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                $input->getOption('tmp') ? 'temporary disk usage' : 'disk usage',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to'])
            ));
        }

        $table->render($rows, self::$tableHeader, $this->defaultColumns);

        return 0;
    }
}
