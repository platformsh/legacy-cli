<?php
namespace Platformsh\Cli\Command\Metrics;

use Khill\Duration\Duration;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AllMetricsCommand extends MetricsCommandBase
{
    private $tableHeader = [
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

    private $defaultColumns = ['timestamp', 'service', 'cpu_percent', 'mem_percent', 'disk_percent', 'tmp_disk_percent'];

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
        $this->addExample('Show metrics in five-minute intervals over the last hour', '-i 5m -r 1h');
        $this->addExample('Show metrics for all SQL services', '--type mariadb,%sql');
        $this->addMetricsOptions()
            ->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timeSpec = $this->validateTimeInput($input);
        if ($timeSpec === false) {
            return 1;
        }

        $this->chooseEnvFilter = $this->filterEnvsByState(['active']);
        $this->validateInput($input, false, true);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->displayEnvironmentHeader();
        }

        // Only request the metrics fields that will be displayed.
        //
        // The fields are the selected column names (according to the $table
        // service), filtered to only those that contain an underscore.
        $fieldNames = array_filter($table->columnsToDisplay($this->tableHeader, $this->defaultColumns), function ($c) { return strpos($c, '_') !== false; });
        $values = $this->fetchMetrics($input, $timeSpec, $this->getSelectedEnvironment(), $fieldNames);
        if ($values === false) {
            return 1;
        }

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'cpu_used' => new Field('cpu_used', Field::FORMAT_ROUNDED_2DP),
            'cpu_limit' => new Field('cpu_limit', Field::FORMAT_ROUNDED_2DP),
            'cpu_percent' => new Field('cpu_percent', Field::FORMAT_PERCENT),

            'mem_used' => new Field('mem_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'mem_limit' => new Field('mem_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'mem_percent' => new Field('mem_percent', Field::FORMAT_PERCENT),

            'disk_used' => new Field('disk_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'disk_limit' => new Field('disk_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'disk_percent' => new Field('disk_percent', Field::FORMAT_PERCENT),

            'tmp_disk_used' => new Field('tmp_disk_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'tmp_disk_limit' => new Field('tmp_disk_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_DISK),
            'tmp_disk_percent' => new Field('tmp_disk_percent', Field::FORMAT_PERCENT),

            'inodes_used' => new Field('inodes_used', Field::FORMAT_ROUNDED),
            'inodes_limit' => new Field('inodes_used', Field::FORMAT_ROUNDED),
            'inodes_percent' => new Field('inodes_percent', Field::FORMAT_PERCENT),

            'tmp_inodes_used' => new Field('tmp_inodes_used', Field::FORMAT_ROUNDED),
            'tmp_inodes_limit' => new Field('tmp_inodes_used', Field::FORMAT_ROUNDED),
            'tmp_inodes_percent' => new Field('tmp_inodes_percent', Field::FORMAT_PERCENT),
        ]);

        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Metrics at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($timeSpec->getInterval()),
                $formatter->formatDate($timeSpec->getStartTime()),
                $formatter->formatDate($timeSpec->getEndTime())
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You can run the <info>cpu</info>, <info>disk</info> and <info>mem</info> commands for more detail.');
        }

        return 0;
    }
}
