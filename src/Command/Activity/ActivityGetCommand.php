<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:get')
            ->setDescription('View detailed information on a single activity')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (when selecting a default activity)')
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled')
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addExample('Find the time a project was created', '--all --type project.create -P completed_at');
        $this->addExample('Find the duration (in seconds) of the last activity', '-P duration');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all') || $input->getArgument('id'));

        /** @var ActivityLoader $loader */
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

        /** @var Table $table */
        $table = $this->getService('table');
        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $properties = $activity->getProperties();

        if (!$input->getOption('property') && !$table->formatIsMachineReadable()) {
            $properties['description'] = ActivityMonitor::getFormattedDescription($activity, true);
        } else {
            $properties['description'] = $activity->description;
        }

        // Add the fake "duration" property.
        if (!isset($properties['duration'])) {
            $properties['duration'] = (new \Platformsh\Cli\Model\Activity())->getDuration($activity);
        }

        if ($property = $input->getOption('property')) {
            $formatter->displayData($output, $properties, $property);
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
            $rows[] = $formatter->format($value, $property);
        }

        $table->renderSimple($rows, $header);

        if (!$table->formatIsMachineReadable()) {
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the log for this activity, run: <info>%s activity:log %s</info>',
                $executable,
                $activity->id
            ));
            $this->stdErr->writeln(sprintf(
                'To list activities, run: <info>%s activities</info>',
                $executable
            ));
        }

        return 0;
    }
}
