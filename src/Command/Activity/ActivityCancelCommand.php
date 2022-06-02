<?php
namespace Platformsh\Cli\Command\Activity;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityCancelCommand extends ActivityCommandBase
{
    protected static $defaultName = 'activity:cancel';
    protected static $defaultDescription = 'Cancel an activity';

    private $activityService;
    private $api;
    private $config;
    private $formatter;
    private $loader;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityLoader $loader,
        ActivityService $activityService,
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector
    )
    {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->loader = $loader;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent cancellable activity.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('exclude-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude by type (when selecting a default activity).' . "\n" . ArrayArgument::SPLIT_HELP)
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, $input->getOption('all') || $input->getArgument('id'));

        $executable = $this->config->get('application.executable');

        if ($selection->hasEnvironment() && !$input->getOption('all')) {
            $apiResource = $selection->getEnvironment();
        } else {
            $apiResource = $selection->getProject();
        }

        $id = $input->getArgument('id');
        if ($id) {
            $activity = $selection->getProject()->getActivity($id);
            if (!$activity) {
                $activity = $this->api->matchPartialId($id, $this->loader->loadFromInput($apiResource, $input, 10, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel') ?: [], 'Activity');
            }
        } else {
            $activities = $this->loader->loadFromInput($apiResource, $input, 10, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel');
            $activities = \array_filter($activities, function (Activity $activity) {
                return $activity->operationAvailable('cancel');
            });
            if (\count($activities) === 0) {
                $this->stdErr->writeln('No cancellable activities found');

                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To list incomplete activities, run: <info>%s act -i</info>', $executable));

                return 1;
            }
            $choices = [];
            $byId = [];
            $this->api->sortResources($activities, 'created_at');
            foreach ($activities as $activity) {
                $byId[$activity->id] = $activity;
                $choices[$activity->id] = \sprintf(
                    '%s: %s (%s)',
                    $this->formatter->formatDate($activity->created_at),
                    $this->activityService->getFormattedDescription($activity),
                    $this->activityService->formatState($activity->state)
                );
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an activity to cancel:', key($choices), true);
            $activity = $byId[$id];
        }

        $this->stdErr->writeln(sprintf(
            'Cancelling the activity <info>%s</info> (%s)...',
            $activity->id,
            $this->activityService->getFormattedDescription($activity)
        ));

        try {
            $activity->cancel();
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400 && \strpos($e->getMessage(), 'cannot be cancelled in its current state')) {
                $activity->refresh();
                $this->stdErr->writeln(\sprintf('The activity cannot be cancelled in its current state (<error>%s</error>).', $activity->state));
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf("To view this activity's log, run: <comment>%s activity:log %s</comment>", $executable, $activity->id));
                return 1;
            }
            throw $e;
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The activity was successfully cancelled.');

        $this->stdErr->writeln('');
        $this->stdErr->writeln(\sprintf("To view this activity's log, run: <info>%s activity:log %s</info>", $executable, $activity->id));
        $this->stdErr->writeln(\sprintf('To list all activities, run: <info>%s act</info>', $executable));

        return 0;
    }
}
