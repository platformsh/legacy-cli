<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityGetCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:get')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter recent activities by type')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view')
            ->setDescription('View detailed information on a single activity');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addExample('Find the time a project was created', '--all --type project.create -P completed_at');
        $this->addExample('Find the duration (in seconds) of the last activity', '-P duration');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all'));

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $this->getSelectedProject()
                ->getActivity($id);
            if (!$activity) {
                $this->stdErr->writeln("Activity not found: <error>$id</error>");

                return 1;
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

        /** @var Table $table */
        $table = $this->getService('table');
        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $properties = $activity->getProperties();

        // Add the activity "description" as a property.
        if (!isset($properties['description'])) {
            $properties['description'] = $activity->getDescription();
        }

        // Calculate the duration of the activity.
        if (!isset($properties['duration'])) {
            $end = strtotime($activity->isComplete() ? $activity->completed_at : $activity->updated_at);
            $created = strtotime($activity->created_at);
            $start = !empty($activity->started_at) ? strtotime($activity->started_at) : 0;
            $start = $start !== 0 && $start !== $end ? $start : $created;
            $properties['duration'] = $end - $start > 0 ? $end - $start : null;
        }

        if ($property = $input->getOption('property')) {
            $formatter->displayData($output, $properties, $property);
            return 0;
        }

        unset($properties['payload'], $properties['log']);

        $this->stdErr->writeln(
            'These properties have been omitted for brevity: <comment>payload</comment> and <comment>log</comment>.'
            . ' You can still view them with the -P (--property) option.',
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
