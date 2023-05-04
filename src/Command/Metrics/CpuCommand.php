<?php
namespace Platformsh\Cli\Command\Metrics;

use Khill\Duration\Duration;
use Platformsh\Cli\Model\Metrics\Field;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CpuCommand extends MetricsCommandBase
{
    protected $stability = self::STABILITY_BETA;

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
        $this->setName('metrics:cpu')
            ->setAliases(['cpu'])
            ->setDescription('Show CPU usage of an environment');
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

        $this->validateInput($input, false, true);

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->displayEnvironmentHeader();
        }

        $values = $this->fetchMetrics($input, $timeSpec, $this->getSelectedEnvironment(), ['cpu_used', 'cpu_percent', 'cpu_limit']);
        if ($values === false) {
            return 1;
        }

        $rows = $this->buildRows($values, [
            'used' => new Field('cpu_used', Field::FORMAT_ROUNDED_2DP),
            'limit' => new Field('cpu_limit', Field::FORMAT_ROUNDED_2DP),
            'percent' => new Field('cpu_percent', Field::FORMAT_PERCENT),
        ]);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf(
                'Average CPU usage at <info>%s</info> intervals from <info>%s</info> to <info>%s</info>:',
                (new Duration())->humanize($timeSpec->getInterval()),
                \date('Y-m-d H:i:s', $timeSpec->getStartTime()),
                \date('Y-m-d H:i:s', $timeSpec->getEndTime())
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        return 0;
    }
}
