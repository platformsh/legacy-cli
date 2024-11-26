<?php
namespace Platformsh\Cli\Command\Metrics;

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
    private $tableHeader = [
        'timestamp' => 'Timestamp',
        'service' => 'Service',
        'type' => 'Type',
        'used' => 'Used',
        'limit' => 'Limit',
        'percent' => 'Used %',
    ];

    private $defaultColumns = ['timestamp', 'service', 'used', 'limit', 'percent'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
        $this->addMetricsOptions()
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

        $this->validateInput($input, false, true);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->displayEnvironmentHeader();
        }

        $values = $this->fetchMetrics($input, $timeSpec, $this->getSelectedEnvironment(), ['mem_used', 'mem_percent', 'mem_limit']);
        if ($values === false) {
            return 1;
        }

        $bytes = $input->getOption('bytes');

        $rows = $this->buildRows($values, [
            'used' => new Field('mem_used', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'limit' => new Field('mem_limit', $bytes ? Field::FORMAT_ROUNDED : Field::FORMAT_MEMORY),
            'percent' => new Field('mem_percent', Field::FORMAT_PERCENT),
        ]);

        if (!$table->formatIsMachineReadable()) {
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
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
