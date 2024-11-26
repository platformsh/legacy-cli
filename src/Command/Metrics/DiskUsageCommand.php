<?php
namespace Platformsh\Cli\Command\Metrics;

use Khill\Duration\Duration;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiskUsageCommand extends MetricsCommandBase
{
    private $tableHeader = [
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
    private $defaultColumns = ['timestamp', 'service', 'used', 'limit', 'percent', 'ipercent', 'tmp_percent'];
    private $tmpReportColumns = ['timestamp', 'service', 'tmp_used', 'tmp_limit', 'tmp_percent', 'tmp_ipercent'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('metrics:disk-usage')
            ->setAliases(['disk'])
            ->setDescription('Show disk usage of an environment')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addMetricsOptions()
            ->addOption('tmp', null, InputOption::VALUE_NONE, 'Report temporary disk usage (shows columns: ' . implode(', ', $this->tmpReportColumns) . ')')
            ->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeSpec = $this->validateTimeInput($input);
        if ($timeSpec === false) {
            return 1;
        }

        if ($input->getOption('tmp')) {
            $input->setOption('columns', $this->tmpReportColumns);
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->removeDeprecatedColumns(['interval'], '', $input, $output);

        $this->validateInput($input, false, true);

        if (!$table->formatIsMachineReadable()) {
            $this->displayEnvironmentHeader();
        }

        $values = $this->fetchMetrics($input, $timeSpec, $this->getSelectedEnvironment(), ['disk_used', 'disk_percent', 'disk_limit', 'inodes_used', 'inodes_percent', 'inodes_limit']);
        if ($values === false) {
            return 1;
        }

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'used' => new Field('disk_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'limit' => new Field('disk_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'percent' => new Field('disk_percent', Field::FORMAT_PERCENT),

            'iused' => new Field('inodes_used', FIELD::FORMAT_ROUNDED),
            'ilimit' => new Field('inodes_limit', FIELD::FORMAT_ROUNDED),
            'ipercent' => new Field('inodes_percent', Field::FORMAT_PERCENT),

            'tmp_used' => new Field('tmp_disk_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'tmp_limit' => new Field('tmp_disk_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'tmp_percent' => new Field('tmp_disk_percent', Field::FORMAT_PERCENT),

            'tmp_iused' => new Field('tmp_inodes_used', Field::FORMAT_ROUNDED),
            'tmp_ilimit' => new Field('tmp_inodes_used', Field::FORMAT_ROUNDED),
            'tmp_ipercent' => new Field('tmp_inodes_percent', Field::FORMAT_PERCENT),
        ]);

        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Average %s at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                $input->getOption('tmp') ? 'temporary disk usage' : 'disk usage',
                (new Duration())->humanize($timeSpec->getInterval()),
                $formatter->formatDate($timeSpec->getStartTime()),
                $formatter->formatDate($timeSpec->getEndTime())
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        return 0;
    }
}
