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
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Log refresh interval (seconds). Set to 0 to disable refreshing.',
                1
            )
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter recent activities by type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments')
            ->setDescription('Display the log for an activity');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Display the log for the last push on the current environment', '--type environment.push')
            ->addExample('Display the log for the last activity on the current project', '--all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all') || $input->getArgument('id'));

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $this->getSelectedProject()
                             ->getActivity($id);
            if (!$activity) {
                $activities = $this->getSelectedEnvironment()
                    ->getActivities(0, $input->getOption('type'));
                $activity = $this->api()->matchPartialId($id, $activities, 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            if ($this->hasSelectedEnvironment() && !$input->getOption('all')) {
                $activities = $this->getSelectedEnvironment()
                    ->getActivities(1, $input->getOption('type'));
            } else {
                $activities = $this->getSelectedProject()
                    ->getActivities(1, $input->getOption('type'));
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
            sprintf('<info>Description: </info>%s', ActivityMonitor::getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $formatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', ActivityMonitor::formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $refresh = $input->getOption('refresh');
        if ($refresh > 0 && !$this->runningViaMulti && !$activity->isComplete()) {
            /** @var ActivityMonitor $monitor */
            $monitor = $this->getService('activity_monitor');
            $monitor->waitAndLog($activity, null, null, $refresh, false);

            // Once the activity is complete, something has probably changed in
            // the project's environments, so this is a good opportunity to
            // clear the cache.
            $this->api()->clearEnvironmentsCache($activity->project);
        } else {
            $output->writeln(rtrim($activity->log));
        }

        return 0;
    }
}
