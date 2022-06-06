<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLogCommand extends ActivityCommandBase
{
    protected static $defaultName = 'activity:log';
    protected static $defaultDescription = 'Display the log for an activity';

    private $activityService;
    private $api;
    private $config;
    private $loader;
    private $selector;
    private $propertyFormatter;

    public function __construct(
        ActivityLoader $activityLoader,
        ActivityService $activityService,
        Api $api,
        Config $config,
        Selector $selector,
        PropertyFormatter $formatter
    ) {
        $this->loader = $activityLoader;
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->propertyFormatter = $formatter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.');
        $this->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Activity refresh interval (seconds). Set to 0 to disable refreshing.',
                3
            )
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->propertyFormatter->configureInput($definition);

        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all') || $input->getArgument('id'));

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $apiResource = $selection->getEnvironment();
        } else {
            $apiResource = $selection->getProject();
        }

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $apiResource->getActivity($id);
            if (!$activity) {
                $activity = $this->api->matchPartialId($id, $this->loader->loadFromInput($apiResource, $input, 10) ?: [], 'Activity');
            }
        } else {
            $activities = $this->loader->loadFromInput($apiResource, $input, 1);
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $this->stdErr->writeln([
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Type: </info>%s', $activity->type),
            sprintf('<info>Description: </info>%s', $this->activityService->getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $this->propertyFormatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', $this->activityService->formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $refresh = $input->getOption('refresh');
        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config->getWithDefault('application.date_format', 'c');
        }

        if ($refresh > 0 && !$this->runningViaMulti && !$activity->isComplete() && $activity->state !== Activity::STATE_CANCELLED) {
            $this->activityService->waitAndLog($activity, $refresh, $timestamps, false, $output);

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api->clearEnvironmentsCache($activity->project);
        } else {
            $items = $activity->readLog();
            $output->write($this->activityService->formatLog($items, $timestamps));
        }

        return 0;
    }
}
