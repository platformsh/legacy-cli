<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:activity:log', description: 'Display the log for an integration activity')]
class IntegrationActivityLogCommand extends IntegrationCommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('integration', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addArgument('activity', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent integration activity.')
            ->addOption('timestamps', 't', InputOption::VALUE_NONE, 'Display a timestamp next to each message');
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

        $integration = $this->selectIntegration($project, $input->getArgument('integration'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $id = $input->getArgument('activity');
        if ($id) {
            $activity = $project->getActivity($id);
            if (!$activity) {
                /** @var Activity $activity */
                $activity = $this->api->matchPartialId($id, $integration->getActivities(), 'Activity');
            }
        } else {
            $activities = $integration->getActivities();
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
            sprintf('<info>Description: </info>%s', ActivityMonitor::getFormattedDescription($activity)),
            sprintf('<info>Created: </info>%s', $this->propertyFormatter->format($activity->created_at, 'created_at')),
            sprintf('<info>State: </info>%s', ActivityMonitor::formatState($activity->state)),
            '<info>Log: </info>',
        ]);

        $timestamps = $input->getOption('timestamps');
        if ($timestamps && $input->hasOption('date-fmt') && $input->getOption('date-fmt') !== null) {
            $timestamps = $input->getOption('date-fmt');
        } elseif ($timestamps) {
            $timestamps = $this->config->getStr('application.date_format');
        }
        if (!$this->runningViaMulti && !$activity->isComplete() && $activity->state !== Activity::STATE_CANCELLED) {
            $this->activityMonitor->waitAndLog($activity, 3, $timestamps, false, $output);

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
