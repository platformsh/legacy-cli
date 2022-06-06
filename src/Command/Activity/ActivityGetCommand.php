<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityGetCommand extends ActivityCommandBase
{
    protected static $defaultName = 'activity:get';
    protected static $defaultDescription = 'View detailed information on a single activity';

    private $activityService;
    private $loader;
    private $selector;
    private $table;
    private $formatter;
    private $api;
    private $config;

    public function __construct(
        ActivityService $activityService,
        ActivityLoader $activityLoader,
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->activityService = $activityService;
        $this->loader = $activityLoader;
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent activity.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by state (when selecting a default activity): in_progress, pending, complete, or cancelled.' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Filter by result (when selecting a default activity): success or failure')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE,
                'Include only incomplete activities (when selecting a default activity).'
                . "\n" . 'This is a shorthand for <info>--state=in_progress,pending</info>')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);

        $this->addExample('Find the time a project was created', '--all --type project.create -P completed_at');
        $this->addExample('Find the duration (in seconds) of the last activity', '-P duration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all') || $input->getArgument('id'));

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $apiResource = $selection->getEnvironment();
        } else {
            $apiResource = $selection->getProject();
        }

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $selection->getProject()->getActivity($id);
            if (!$activity) {
                $activity = $this->api->matchPartialId($id, $this->loader->loadFromInput($apiResource, $input, 10) ?: [], 'Activity');
            }
        } else {
            $activities = $this->loader->loadFromInput($apiResource, $input, 1);
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No activities found');

                return 1;
            }
        }

        $properties = $activity->getProperties();

        if (!$input->getOption('property') && !$this->table->formatIsMachineReadable()) {
            $properties['description'] = $this->activityService->getFormattedDescription($activity, true);
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
