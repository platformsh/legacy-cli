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
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:activity:get', description: 'View detailed information on a single integration activity')]
class IntegrationActivityGetCommand extends IntegrationCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('integration', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->addArgument('activity', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent integration activity.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '[Deprecated option, not used]');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
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

        /** @var \Platformsh\Client\Model\Activity $activity */
        $properties = $activity->getProperties();

        if (!$input->getOption('property') && !$this->table->formatIsMachineReadable()) {
            $properties['description'] = ActivityMonitor::getFormattedDescription($activity, true);
        } else {
            $properties['description'] = $activity->description;
        }

        // Add the fake "duration" property.
        if (!isset($properties['duration'])) {
            $properties['duration'] = (new \Platformsh\Cli\Model\Activity())->getDuration($activity);
        }

        if ($property = $input->getOption('property')) {
            $this->propertyFormatter->displayData($output, $properties, $property);
            return 0;
        }


        // The activity "log" property is going to be removed.
        unset($properties['payload'], $properties['log']);

        $this->stdErr->writeln(
            'The <comment>payload</comment> property has been omitted for brevity.'
            . ' You can still view it with the -P (--property) option.',
            OutputInterface::VERBOSITY_VERBOSE,
        );

        $header = [];
        $rows = [];
        foreach ($properties as $property => $value) {
            $header[] = $property;
            $rows[] = $this->propertyFormatter->format($value, $property);
        }

        $this->table->renderSimple($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for this activity, run: <info>%s integration:activity:log %s %s</info>',
                $executable,
                $integration->id,
                $activity->id,
            ));
            $this->stdErr->writeln(sprintf(
                'To list activities for this integration, run: <info>%s integration:activities %s</info>',
                $executable,
                $integration->id,
            ));
        }

        return 0;
    }
}
