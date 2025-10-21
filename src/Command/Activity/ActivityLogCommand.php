<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'activity:log', description: 'Display the log for an activity')]
class ActivityLogCommand extends ActivityCommandBase
{
    public function __construct(private readonly ActivityLoader $activityLoader, private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Activity refresh interval (seconds). Set to 0 to disable refreshing.',
                3,
            )
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by type (when selecting a default activity).'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard for the type, e.g. '%var%' to select variable-related activities.",
                null,
                ActivityLoader::getAvailableTypes(),
            )
            ->addOption(
                'exclude-type',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude by type (when selecting a default activity).'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard to exclude types.",
                null,
                ActivityLoader::getAvailableTypes(),
            )
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP, null, self::STATE_VALUES)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure', null, self::RESULT_VALUES)
            ->addOption(
                'incomplete',
                'i',
                InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>',
            )
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all')
            ->addExample('Display the log for the last push, with microsecond timestamps', "-a -t --type %push --date-fmt 'Y-m-d\TH:i:s.uP'");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: !($input->getOption('all') || $input->getArgument('id'))));

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $apiResource = $selection->getEnvironment();
        } else {
            $apiResource = $selection->getProject();
        }

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $selection->getProject()
                ->getActivity($id);
            if (!$activity) {
                /** @var Activity $activity */
                $activity = $this->api->matchPartialId($id, $this->activityLoader->loadFromInput($apiResource, $input, self::DEFAULT_FIND_LIMIT) ?: [], 'Activity');
            }
        } else {
            $activities = $this->activityLoader->loadFromInput($apiResource, $input, 1);
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $this->stdErr->writeln([
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Type: </info>%s', $activity->type),
            sprintf('<info>Description: </info>%s', ActivityMonitor::getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $this->propertyFormatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', ActivityMonitor::formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $refresh = $input->getOption('refresh');
        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config->getStr('application.date_format');
        }
        if ($refresh > 0 && !$this->runningViaMulti && !$activity->isComplete() && $activity->state !== Activity::STATE_CANCELLED) {
            $this->activityMonitor->waitAndLog($activity, $refresh, $timestamps, false, $output);

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api->clearEnvironmentsCache($activity->project);
        } else {
            $items = $activity->readLog();
            $output->write($this->activityMonitor->formatLog($items, $timestamps));
        }

        return 0;
    }
}
