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

class MemCommand extends MetricsCommandBase
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
    );

    /**
     * @var array
     */
    private $defaultColumns = array('timestamp', 'service', 'used', 'limit', 'percent');

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('metrics:memory')
            ->setAliases(array('mem', 'memory'))
            ->setDescription('Show memory usage of an environment')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
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
        $result = $this->processQuery($input, array(MetricKind::API_TYPE_MEMORY), array(MetricKind::API_AGG_AVG));

        $values = $result[0];
        $environment = $result[1];

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, array(
            'used' => new Field(
                $bytes ? Format::ROUNDED : Format::MEMORY,
                new SourceField(MetricKind::MEMORY_USED, Aggregation::AVG)
            ),
            'limit' => new Field(
                $bytes ? Format::ROUNDED : Format::MEMORY,
                new SourceField(MetricKind::MEMORY_LIMIT, Aggregation::MAX)
            ),
            'percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::MEMORY_USED, Aggregation::AVG),
                    new SourceField(MetricKind::MEMORY_LIMIT, Aggregation::MAX)
                ),
                false
            ),
        ), $environment);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Average memory usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to'])
            ));
        }

        $table->render($rows, self::$tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
        }

        return 0;
    }
}
