<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'activity:cancel', description: 'Cancel an activity')]
class ActivityCancelCommand extends ActivityCommandBase
{
    public function __construct(private readonly ActivityLoader $activityLoader, private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent cancellable activity.')
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by type (when selecting a default activity).'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard for the type, e.g. '%var%' to select variable-related activities.",
                null,
                ActivityLoader::getAvailableTypes(),
            )
            ->addOption(
                'exclude-type',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude by type (when selecting a default activity).'
                . "\n" . ArrayArgument::SPLIT_HELP
                . "\nThe % or * characters can be used as a wildcard to exclude types.",
                null,
                ActivityLoader::getAvailableTypes(),
            )
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: !($input->getOption('all') || $input->getArgument('id'))));

        $executable = $this->config->getStr('application.executable');

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
                /** @var Activity $activity */
                $activity = $this->api->matchPartialId($id, $this->activityLoader->loadFromInput($apiResource, $input, self::DEFAULT_FIND_LIMIT, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel') ?: [], 'Activity');
            }
        } else {
            $activities = $this->activityLoader->loadFromInput($apiResource, $input, 10, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel');
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
                    $this->propertyFormatter->formatDate($activity->created_at),
                    ActivityMonitor::getFormattedDescription($activity),
                    ActivityMonitor::formatState($activity->state),
                );
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an activity to cancel:', (string) key($choices));
            $activity = $byId[$id];
        }

        $this->stdErr->writeln('Cancelling the activity ' . ActivityMonitor::getFormattedDescription($activity, true, true, 'cyan'));

        try {
            $activity->cancel();
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 400 && \strpos($e->getMessage(), 'cannot be cancelled')) {
                if (\strpos($e->getMessage(), 'cannot be cancelled in its current state')) {
                    $activity->refresh();
                    $this->stdErr->writeln(\sprintf('The activity cannot be cancelled in its current state (<error>%s</error>).', $activity->state));
                } else {
                    $this->stdErr->writeln(\sprintf('The activity <error>%s</error> cannot be cancelled.', $activity->id));
                }
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
