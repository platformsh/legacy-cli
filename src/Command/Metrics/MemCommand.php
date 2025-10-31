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

#[AsCommand(name: 'metrics:memory', description: 'Show memory usage of an environment', aliases: ['mem', 'memory'])]
class MemCommand extends MetricsCommandBase
{
    /** @var array<string, string> */
    private const TABLE_HEADER = [
        'timestamp' => 'Timestamp',
        'service' => 'Service',
        'type' => 'Type',
        'used' => 'Used',
        'limit' => 'Limit',
        'percent' => 'Used %',
    ];

    /** @var string[] */
    private array $defaultColumns = ['timestamp', 'service', 'used', 'limit', 'percent'];

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
        $this->addMetricsOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), self::TABLE_HEADER, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$values, $environment] = $this->processQuery($input, [MetricKind::API_TYPE_MEMORY], [MetricKind::API_AGG_AVG]);

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'used' => new Field(
                $bytes ? Format::Rounded : Format::Memory,
                new SourceField(MetricKind::MemoryUsed, Aggregation::Avg),
            ),
            'limit' => new Field(
                $bytes ? Format::Rounded : Format::Memory,
                new SourceField(MetricKind::MemoryLimit, Aggregation::Max),
            ),
            'percent' => new Field(
                Format::Percent,
                new SourceFieldPercentage(
                    new SourceField(MetricKind::MemoryUsed, Aggregation::Avg),
                    new SourceField(MetricKind::MemoryLimit, Aggregation::Max)
                ),
                false,
            ),
        ], $environment);

        if (!$this->table->formatIsMachineReadable()) {
            $formatter = $this->propertyFormatter;
            $this->stdErr->writeln(\sprintf(
                'Average memory usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($values['_grain']),
                $formatter->formatDate($values['_from']),
                $formatter->formatDate($values['_to']),
            ));
        }

        $this->table->render($rows, self::TABLE_HEADER, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
        }

        return 0;
    }
}
