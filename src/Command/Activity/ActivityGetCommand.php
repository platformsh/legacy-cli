<?php
namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'activity:get', description: 'View detailed information on a single activity')]
class ActivityGetCommand extends ActivityCommandBase
{
    public function __construct(private readonly ActivityLoader $activityLoader, private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by type (when selecting a default activity).'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard for the type, e.g. '%var%' to select variable-related activities."
            )
            ->addOption('exclude-type', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude by type (when selecting a default activity).'
                 . "\n" . ArrayArgument::SPLIT_HELP
                 . "\nThe % or * characters can be used as a wildcard to exclude types."
            )
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addExample('Find the time a project was created', '--all --type project.create -P completed_at');
        $this->addExample('Find the duration (in seconds) of the last activity', '-P duration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new \Platformsh\Cli\Selector\SelectorConfig(envRequired: !($input->getOption('all') || $input->getArgument('id'))));

        $loader = $this->activityLoader;

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
                $activity = $this->api->matchPartialId($id, $loader->loadFromInput($apiResource, $input, 10) ?: [], 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            $activities = $loader->loadFromInput($apiResource, $input, 1);
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $table = $this->table;
        $formatter = $this->propertyFormatter;

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
            $executable = $this->config->get('application.executable');
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
