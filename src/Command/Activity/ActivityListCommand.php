<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends ActivityCommandBase
{
    private $tableHeader = [
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
    private $defaultColumns = ['id', 'created', 'description', 'progress', 'state', 'result'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:list')
            ->setAliases(['activities', 'act']);

        // Add the --type option, with a link to help if configured.
        $typeDescription = 'Filter activities by type';
        if ($this->config()->has('service.activity_type_list_url')) {
            $typeDescription .= "\nFor a list of types see: <info>" . $this->config()->get('service.activity_type_list_url') . '</info>';
        }
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            $typeDescription
            . "\n" . ArrayArgument::SPLIT_HELP
            . "\nThe first part of the activity name can be omitted, e.g. 'cron' can select 'environment.cron' activities."
            . "\nThe % or * characters can be used as a wildcard, e.g. '%var%' to select variable-related activities."
        );
        $this->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Exclude activities by type.'
            . "\n" . ArrayArgument::SPLIT_HELP
            . "\nThe first part of the activity name can be omitted, e.g. 'cron' can exclude 'environment.cron' activities."
            . "\nThe % or * characters can be used as a wildcard to exclude types."
        );

        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', self::DEFAULT_LIST_LIMIT)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state: in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result: success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List activities on all environments')
            ->setDescription('Get a list of activities for an environment or project');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type push')
             ->addExample('List all recent activities excluding crons and redeploys', "--exclude-type '*.cron,*.backup*'")
             ->addExample('List pushes made before 15 March', '--type push --start 2015-03-15')
             ->addExample('List up to 25 incomplete activities', '--limit 25 -i')
             ->addExample('Include the activity type in the table', '--columns +type');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all'));

        $project = $this->getSelectedProject();

        if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $this->getSelectedEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');
        $activities = $loader->loadFromInput($apiResource, $input);
        if ($activities === []) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $defaultColumns = $this->defaultColumns;

        if (!$environmentSpecific) {
            $defaultColumns[] = 'environments';
        }

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
                'environments' => implode(', ', $activity->environments),
            ];
            $timings = $activity->getProperty('timings', false, false) ?: [];
            foreach ($timingTypes as $timingType) {
                $row['time_' . $timingType] = isset($timings[$timingType]) ? (string) $timings[$timingType] : '';
            }
            $rows[] = $row;
        }

        if (!$table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api()->getProjectLabel($project),
                    $this->api()->getEnvironmentLabel($apiResource)
                ));
            } else {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s:',
                    $this->api()->getProjectLabel($project)
                ));
            }
        }

        $table->render($rows, $this->tableHeader, $defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');

            // TODO make this more deterministic by fetching limit+1 activities
            $max = ((int) $input->getOption('limit') ?: self::DEFAULT_LIST_LIMIT);
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('More activities may be available.');
                $this->stdErr->writeln(sprintf(
                    'To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.',
                    $max
                ));
                $this->suggestExclusions($activities);
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for an activity, run: <info>%s activity:log [id]</info>',
                $executable
            ));
            $this->stdErr->writeln(sprintf(
                'To view more information about an activity, run: <info>%s activity:get [id]</info>',
                $executable
            ));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('For more information, run: <info>%s activity:list -h</info>', $executable));
        }

        return 0;
    }

    private function suggestExclusions(array $activities)
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
