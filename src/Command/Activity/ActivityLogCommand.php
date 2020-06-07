<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityLogCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:log')
            ->setDescription('Display the log for an activity')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Activity refresh interval (seconds). Set to 0 to disable refreshing.',
                3
            )
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (when selecting a default activity)')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled')
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all') || $input->getArgument('id'));

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');

        if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
            $apiResource = $this->getSelectedEnvironment();
        } else {
            $apiResource = $this->getSelectedProject();
        }

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $this->getSelectedProject()
                ->getActivity($id);
            if (!$activity) {
                $activity = $this->api()->matchPartialId($id, $loader->loadFromInput($apiResource, $input, 10) ?: [], 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            $activities = $loader->loadFromInput($apiResource, $input, 1);
            if ($activities === false) {
                return 1;
            }
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $this->stdErr->writeln([
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Type: </info>%s', $activity->type),
            sprintf('<info>Description: </info>%s', ActivityMonitor::getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $formatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', ActivityMonitor::formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $refresh = $input->getOption('refresh');
        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config()->getWithDefault('application.date_format', 'c');
        }

        /** @var ActivityMonitor $monitor */
        $monitor = $this->getService('activity_monitor');
        if ($refresh > 0 && !$this->runningViaMulti && !$activity->isComplete()) {
            $monitor->waitAndLog($activity, $refresh, $timestamps, false, $output);

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api()->clearEnvironmentsCache($activity->project);
        } else {
            $items = $activity->readLog();
            $output->write($monitor->formatLog($items, $timestamps));
        }

        return 0;
    }
}
