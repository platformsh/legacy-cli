<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:activity:list', description: 'Get a list of activities for an integration', aliases: ['integration:activities'])]
class IntegrationActivityListCommand extends IntegrationCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
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
    /** @var string[] */
    private array $defaultColumns = ['id', 'created', 'description', 'type', 'state', 'result'];
    public function __construct(private readonly ActivityLoader $activityLoader, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHiddenAliases(['int:act', 'i:act'])
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter activities by type.'
                . "\n" . ArrayArgument::SPLIT_HELP,
            )
            ->addOption(
                'exclude-type',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude activities by type.'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard to exclude types.",
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', self::DEFAULT_LIST_LIMIT)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['environment']);
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));

        $project = $selection->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }
        $activities = $this->activityLoader->loadFromInput($integration, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $timingTypes = ['execute', 'wait', 'build', 'deploy'];

        $rows = [];
        foreach ($activities as $activity) {
            $row = [
                'id' => new AdaptiveTableCell($activity->id, ['wrap' => false]),
                'created' => $this->propertyFormatter->format($activity['created_at'], 'created_at'),
                'completed' => $this->propertyFormatter->format($activity['completed_at'], 'completed_at'),
                'description' => ActivityMonitor::getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                'type' => new AdaptiveTableCell($activity->type, ['wrap' => false]),
                'progress' => $activity->getCompletionPercent() . '%',
                'state' => ActivityMonitor::formatState($activity->state),
                'result' => ActivityMonitor::formatResult($activity, !$this->table->formatIsMachineReadable()),
            ];
            $timings = $activity->getProperty('timings', false, false) ?: [];
            foreach ($timingTypes as $timingType) {
                $row['time_' . $timingType] = isset($timings[$timingType]) ? (string) $timings[$timingType] : '';
            }
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Activities on the project %s, integration <info>%s</info> (%s):',
                $this->api->getProjectLabel($project),
                $integration->id,
                $integration->type,
            ));
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : self::DEFAULT_LIST_LIMIT;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s integration:activity:list -h</info>',
                    $max,
                    $executable,
                ));
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s integration:activity:log [integration] [activity]</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s integration:activity:get [integration] [activity]</info>',
                $executable,
            ));
        }

        return 0;
    }
}
