<?php
namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationActivityLogCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:activity:log')
            ->addArgument('integration', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addArgument('activity', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent integration activity.')
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message')
            ->setDescription('Display the log for an integration activity');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $integration = $this->selectIntegration($project, $input->getArgument('integration'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $id = $input->getArgument('activity');
        if ($id) {
            $activity = $project->getActivity($id);
            if (!$activity) {
                $activity = $this->api()->matchPartialId($id, $integration->getActivities(), 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Integration activity not found: <error>$id</error>");

                    return 1;
                }
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

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $this->stdErr->writeln([
            sprintf('<info>Integration ID: </info>%s', $integration->id),
            sprintf('<info>Activity ID: </info>%s', $activity->id),
            sprintf('<info>Type: </info>%s', $activity->type),
            sprintf('<info>Description: </info>%s', ActivityMonitor::getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $formatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', ActivityMonitor::formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config()->getWithDefault('application.date_format', 'c');
        }

        /** @var ActivityMonitor $monitor */
        $monitor = $this->getService('activity_monitor');
        if (!$this->runningViaMulti && !$activity->isComplete()) {
            $monitor->waitAndLog($activity, 3, $timestamps, false, $output);

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
