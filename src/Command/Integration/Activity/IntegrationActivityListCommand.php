<?php

namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationActivityListCommand extends IntegrationCommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'created' => 'Created',
        'completed' => 'Completed',
        'description' => 'Description',
        'progress' => 'Progress',
        'type' => 'Type',
        'state' => 'State',
        'result' => 'Result',
        'time_execute' => 'Exec time (s)',
        'time_wait' => 'Wait time (s)',
        'time_build' => 'Build time (s)',
        'time_deploy' => 'Deploy time (s)',
    ];
    private $defaultColumns = ['id', 'created', 'description', 'type', 'state', 'result'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:activity:list')
            ->setAliases(['integration:activities'])
            ->setHiddenAliases(['int:act', 'i:act'])
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter activities by type.'
                . "\n" . ArrayArgument::SPLIT_HELP
            )
            ->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude activities by type.'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard to exclude types."
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities')
            ->setDescription('Get a list of activities for an integration');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['environment']);
        $this->validateInput($input, true);

        $project = $this->getSelectedProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');
        $activities = $loader->loadFromInput($integration, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $timingTypes = ['execute', 'wait', 'build', 'deploy'];

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                'id' => new AdaptiveTableCell($activity->id, ['wrap' => false]),
                'created' => $formatter->format($activity['created_at'], 'created_at'),
                'completed' => $formatter->format($activity['completed_at'], 'completed_at'),
                'description' => ActivityMonitor::getFormattedDescription($activity, !$table->formatIsMachineReadable()),
                'type' => new AdaptiveTableCell($activity->type, ['wrap' => false]),
                'progress' => $activity->getCompletionPercent() . '%',
                'state' => ActivityMonitor::formatState($activity->state),
                'result' => ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
            ];
            $timings = $activity->getProperty('timings', false, false) ?: [];
            foreach ($timingTypes as $timingType) {
                $row['time_' . $timingType] = isset($timings[$timingType]) ? (string) $timings[$timingType] : '';
            }
            $rows[] = $row;
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Activities on the project %s, integration <info>%s</info> (%s):',
                $this->api()->getProjectLabel($project),
                $integration->id,
                $integration->type
            ));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s integration:activity:list -h</info>',
                    $max,
                    $executable
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s integration:activity:log [integration] [activity]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s integration:activity:get [integration] [activity]</info>',
                $executable
            ));
        }

        return 0;
    }
}
