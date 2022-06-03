<?php
namespace Platformsh\Cli\Command\Integration\Activity;

use Platformsh\Cli\Command\Integration\IntegrationCommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationActivityGetCommand extends IntegrationCommandBase
{
    protected static $defaultName = 'integration:activity:get';
    protected static $defaultDescription = 'View detailed information on a single integration activity';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('integration', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addArgument('activity', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent integration activity.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
        $this->table->configureInput($this->getDefinition());
        $this->formatter->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        $properties = $activity->getProperties();

        if (!$input->getOption('property') && !$this->table->formatIsMachineReadable()) {
            $properties['description'] = $this->activityService->getFormattedDescription($activity, true);
        } else {
            $properties['description'] = $activity->description;
        }

        // Add the fake "duration" property.
        if (!isset($properties['duration'])) {
            $properties['duration'] = (new \Platformsh\Cli\Model\Activity())->getDuration($activity);
        }

        if ($property = $input->getOption('property')) {
            $this->formatter->displayData($output, $properties, $property);
            return 0;
        }


        // The activity "log" property is going to be removed.
        unset($properties['payload'], $properties['log']);

        $this->stdErr->writeln(
            'The <comment>payload</comment> property has been omitted for brevity.'
            . ' You can still view it with the -P (--property) option.',
            OutputInterface::VERBOSITY_VERBOSE
        );

        $header = [];
        $rows = [];
        foreach ($properties as $property => $value) {
            $header[] = $property;
            $rows[] = $this->formatter->format($value, $property);
        }

        $this->table->renderSimple($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for this activity, run: <info>%s integration:activity:log %s %s</info>',
                $executable,
                $integration->id,
                $activity->id
            ));
            $this->stdErr->writeln(sprintf(
                'To list activities for this integration, run: <info>%s integration:activities %s</info>',
                $executable,
                $integration->id
            ));
        }

        return 0;
    }
}
