<?php
namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationActivityLogCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:activity:log';
    protected static $defaultDescription = 'Display the log for an integration activity';

    protected function configure()
    {
        $this
            ->addArgument('integration', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addArgument('activity', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent integration activity.')
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message');
        $this->formatter->configureInput($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO
        // $this->warnAboutDeprecatedOptions(['environment']);
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('integration'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $id = $input->getArgument('activity');
        if ($id) {
            $activity = $project->getActivity($id);
            if (!$activity) {
                $activity = $this->api->matchPartialId($id, $integration->getActivities(), 'Activity');
            }
        } else {
            $activities = $integration->getActivities();
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No integration activities found');

                return 1;
            }
        }

        $this->stdErr->writeln([
            sprintf('<info>Integration ID: </info>%s', $integration->id),
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Type: </info>%s', $activity->type),
            sprintf('<info>Description: </info>%s', $this->activityService->getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $this->formatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', $this->activityService->formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config->getWithDefault('application.date_format', 'c');
        }

        if (!$this->runningViaMulti && !$activity->isComplete() && $activity->state !== Activity::STATE_CANCELLED) {
            $this->activityService->waitAndLog($activity, 3, $timestamps, false, $output);

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
