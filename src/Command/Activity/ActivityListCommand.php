<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'activity:list', description: 'Get a list of activities for an environment or project', aliases: ['activities', 'act'])]
class ActivityListCommand extends ActivityCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'created' => 'Created',
        'completed' => 'Completed',
        'description' => 'Description',
        'type' => 'Type',
        'progress' => 'Progress',
        'state' => 'State',
        'result' => 'Result',
        'environments' => 'Environment(s)',
        'time_execute' => 'Exec time (s)',
        'time_wait' => 'Wait time (s)',
        'time_build' => 'Build time (s)',
        'time_deploy' => 'Deploy time (s)',
    ];

    /** @var string[] */
    private array $defaultColumns = ['id', 'created', 'description', 'progress', 'state', 'result'];

    public function __construct(private readonly ActivityLoader $activityLoader, private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Add the --type option, with a link to help if configured.
        $typeDescription = 'Filter activities by type';
        if ($this->config->has('service.activity_type_list_url')) {
            $typeDescription .= "\nFor a list of types see: <info>" . $this->config->getStr('service.activity_type_list_url') . '</info>';
        }
        $this->addOption(
            'type',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            $typeDescription
            . "\n" . ArrayArgument::SPLIT_HELP
            . "\nThe first part of the activity name can be omitted, e.g. 'cron' can select 'environment.cron' activities."
            . "\nThe % or * characters can be used as a wildcard, e.g. '%var%' to select variable-related activities.",
            null,
            ActivityLoader::getAvailableTypes(),
        );
        $this->addOption(
            'exclude-type',
            'x',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Exclude activities by type.'
            . "\n" . ArrayArgument::SPLIT_HELP
            . "\nThe first part of the activity name can be omitted, e.g. 'cron' can exclude 'environment.cron' activities."
            . "\nThe % or * characters can be used as a wildcard to exclude types.",
            null,
            ActivityLoader::getAvailableTypes(),
        );

        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', self::DEFAULT_LIST_LIMIT)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state: in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP, null, self::STATE_VALUES)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result: success or failure', null, self::RESULT_VALUES)
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List activities on all environments');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type push')
             ->addExample('List all recent activities excluding crons and redeploys', "--exclude-type '*.cron,*.backup*'")
             ->addExample('List pushes made before 15 March', '--type push --start 2015-03-15')
             ->addExample('List up to 25 incomplete activities', '--limit 25 -i');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: !$input->getOption('all')));

        $project = $selection->getProject();

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $selection->getEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }
        $activities = $this->activityLoader->loadFromInput($apiResource, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $defaultColumns = $this->defaultColumns;

        if (!$environmentSpecific) {
            $defaultColumns[] = 'environments';
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
                'environments' => implode(', ', $activity->environments),
            ];
            $timings = $activity->getProperty('timings', false, false) ?: [];
            foreach ($timingTypes as $timingType) {
                $row['time_' . $timingType] = isset($timings[$timingType]) ? (string) $timings[$timingType] : '';
            }
            $rows[] = $row;
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api->getProjectLabel($project),
                    $this->api->getEnvironmentLabel($apiResource),
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s:',
                    $this->api->getProjectLabel($project),
                ));
            }
        }

        $this->table->render($rows, $this->tableHeader, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');

            // TODO make this more deterministic by fetching limit+1 activities
            $max = ((int) $input->getOption('limit') ?: self::DEFAULT_LIST_LIMIT);
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('More activities may be available.');
                $this->stdErr->writeln(sprintf(
                    'To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.',
                    $max,
                ));
                $this->suggestExclusions($activities);
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable,
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable,
            ));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('For more information, run: <info>%s activity:list -h</info>', $executable));
        }

        return 0;
    }

    /**
     * @param Activity[] $activities
     */
    private function suggestExclusions(array $activities): void
    {
        $counts = [];
        foreach ($activities as $activity) {
            $type = $activity->type;
            $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
        }
        if (empty($counts)) {
            return;
        }
        $total = count($activities);
        $suggest = [];
        foreach ($counts as $type => $count) {
            if ($count > 4 && $count / $total >= .4) {
                if (($dotPos = strpos($type, '.')) > 0) {
                    $suggest[] = substr($type, $dotPos + 1);
                } else {
                    $suggest[] = $type;
                }
            }
        }
        if (!empty($suggest)) {
            $this->stdErr->writeln(sprintf('Exclude the most frequent activity %s by adding: <info>-x %s</info>', count($suggest) !== 1 ? 'types' : 'type', implode(' -x ', $suggest)));
        }
    }
}
