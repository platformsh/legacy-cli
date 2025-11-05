<?php
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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CpuCommand extends MetricsCommandBase
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
     * @var PropertyFormatter
     */
    private $propertyFormatter;

    /**
     * @param PropertyFormatter $propertyFormatter
     * @param Selector $selector
     * @param Table $table
     */
    public function __construct(
        PropertyFormatter $propertyFormatter,
        Selector $selector,
        Table $table
    ) {
        $this->propertyFormatter = $propertyFormatter;
        parent::__construct($selector, $table);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('metrics:cpu')
            ->setAliases(array('cpu'))
            ->setDescription('Show CPU usage of an environment');
        $this->addMetricsOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
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
        $result = $this->processQuery($input, array(MetricKind::API_TYPE_CPU), array(MetricKind::API_AGG_AVG));

        $values = $result[0];
        $environment = $result[1];

        $rows = $this->buildRows($values, array(
            'used' => new Field(
                Format::ROUNDED_2P,
                new SourceField(MetricKind::CPU_USED, Aggregation::AVG)
            ),
            'limit' => new Field(
                Format::ROUNDED_2P,
                new SourceField(MetricKind::CPU_LIMIT, Aggregation::MAX)
            ),
            'percent' => new Field(
                Format::PERCENT,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::CPU_USED, Aggregation::AVG),
                    new SourceField(MetricKind::CPU_LIMIT, Aggregation::MAX)
                )
            ),
        ), $environment);

        if (!$this->table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $this->stdErr->writeln(\sprintf(
                'Average CPU usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to'])
            ));
        }

        $this->table->render($rows, self::$tableHeader, $this->defaultColumns);

        return 0;
    }
}
