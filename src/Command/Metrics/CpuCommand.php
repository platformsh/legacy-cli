<?php
namespace Platformsh\Cli\Command\Metrics;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Khill\Duration\Duration;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:cpu', description: 'Show CPU usage of an environment', aliases: ['cpu'])]
class CpuCommand extends MetricsCommandBase
{
    private array $tableHeader = [
        'timestamp' => 'Timestamp',
        'service' => 'Service',
        'type' => 'Type',
        'used' => 'Used',
        'limit' => 'Limit',
        'percent' => 'Used %',
    ];

    private array $defaultColumns = ['timestamp', 'service', 'used', 'limit', 'percent'];
    public function __construct(private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addMetricsOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
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

        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        $table = $this->table;

        if (!$table->formatIsMachineReadable()) {
            $this->selector->ensurePrintedSelection($selection);
        }

        $values = $this->fetchMetrics($input, $timeSpec, $selection->getEnvironment(), ['cpu_used', 'cpu_percent', 'cpu_limit']);
        if ($values === false) {
            return 1;
        }

        $rows = $this->buildRows($values, [
            'used' => new Field('cpu_used', Field::FORMAT_ROUNDED_2DP),
            'limit' => new Field('cpu_limit', Field::FORMAT_ROUNDED_2DP),
            'percent' => new Field('cpu_percent', Field::FORMAT_PERCENT),
        ], $selection->getEnvironment());

        if (!$table->formatIsMachineReadable()) {
            $formatter = $this->propertyFormatter;
            $this->stdErr->writeln(\sprintf(
                'Average CPU usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($timeSpec->getInterval()),
                $formatter->formatDate($timeSpec->getStartTime()),
                $formatter->formatDate($timeSpec->getEndTime())
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        return 0;
    }
}
