<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityListCommand extends ActivityCommandBase
{
    protected static $defaultName = 'activity:list|activities|act';
    protected static $defaultDescription = 'Get a list of activities for an environment or project';

    private $activityLoader;
    private $activityService;
    private $api;
    private $config;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        ActivityLoader $activityLoader,
        ActivityService $activityService,
        Api $api,
        Config $config,
        Selector $selector,
        Table $table,
        PropertyFormatter $formatter
    ) {
        $this->activityLoader = $activityLoader;
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        $this->formatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        // Add the --type option, with a link to help if configured.
        $typeDescription = 'Filter activities by type';
        if ($this->config->has('service.activity_type_list_url')) {
            $typeDescription .= "\nFor a list of types see: <info>" . $this->config->get('service.activity_type_list_url') . '</info>';
        }
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $typeDescription . "\n" . ArrayArgument::SPLIT_HELP);
        $this->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude activities by type.' . "\n" . ArrayArgument::SPLIT_HELP);

        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of results displayed', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only activities created before this date will be listed')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter activities by state: in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter activities by result: success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Only list incomplete activities')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List activities on all environments');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('List recent activities for the current environment')
             ->addExample('List all recent activities for the current project', '--all')
             ->addExample('List recent pushes', '--type environment.push')
             ->addExample('List pushes made before 15 March', '--type environment.push --start 2015-03-15')
             ->addExample('List up to 25 incomplete activities', '--limit 25 -i');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all'));
        $project = $selection->getProject();

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $environmentSpecific = true;
            $apiResource = $selection->getEnvironment();
        } else {
            $environmentSpecific = false;
            $apiResource = $project;
        }

        $activities = $this->activityLoader->loadFromInput($apiResource, $input);
        if (!$activities) {
            $this->stdErr->writeln('No activities found');

            return 1;
        }

        $headers = ['ID', 'Created', 'Completed', 'Description', 'Type', 'Progress', 'State', 'Result', 'Environment(s)'];
        $defaultColumns = ['ID', 'Created', 'Description', 'Type', 'State', 'Result'];

        if (!$environmentSpecific) {
            $defaultColumns[] = 'Environment(s)';
        }

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                new AdaptiveTableCell($activity->id, ['wrap' => false]),
                $this->formatter->format($activity['created_at'], 'created_at'),
                $this->formatter->format($activity['completed_at'], 'completed_at'),
                $this->activityService->getFormattedDescription($activity, !$this->table->formatIsMachineReadable()),
                $activity->getCompletionPercent() . '%',
                $this->activityService->formatState($activity->state),
                $this->activityService->formatResult($activity->result, !$this->table->formatIsMachineReadable()),
                implode(', ', $activity->environments)
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            if ($environmentSpecific) {
                $this->stdErr->writeln(sprintf(
                    'Activities on the project %s, environment %s:',
                    $this->api->getProjectLabel($project),
                    $this->api->getEnvironmentLabel($apiResource)
                ));
            } else {
                $this->stdErr->writeln(
                    sprintf(
                        'Activities on the project %s:',
                        $this->api->getProjectLabel($project)
                    )
                );
            }
        }

        $this->table->render($rows, $headers, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');

            $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
            $maybeMoreAvailable = count($activities) === $max;
            if ($maybeMoreAvailable) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'More activities may be available.'
                    . ' To display older activities, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                    . ' For more information, run: <info>%s activity:list -h</info>',
                    $max,
                    $executable
                ));
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
        }

        return 0;
    }
}
