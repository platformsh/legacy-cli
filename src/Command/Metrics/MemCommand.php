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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics:memory', description: 'Show memory usage of an environment', aliases: ['mem', 'memory'])]
class MemCommand extends MetricsCommandBase
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
        $this
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
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

        $values = $this->fetchMetrics($input, $timeSpec, $selection->getEnvironment(), ['mem_used', 'mem_percent', 'mem_limit']);
        if ($values === false) {
            return 1;
        }

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'used' => new Field('mem_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'limit' => new Field('mem_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'percent' => new Field('mem_percent', Field::FORMAT_PERCENT),
        ], $selection->getEnvironment());

        if (!$table->formatIsMachineReadable()) {
            $formatter = $this->propertyFormatter;
            $this->stdErr->writeln(\sprintf(
                'Average memory usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($timeSpec->getInterval()),
                $formatter->formatDate($timeSpec->getStartTime()),
                $formatter->formatDate($timeSpec->getEndTime())
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->explainHighMemoryServices();
        }

        return 0;
    }
}
