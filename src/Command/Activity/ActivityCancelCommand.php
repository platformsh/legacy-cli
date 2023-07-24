<?php
namespace Platformsh\Cli\Command\Activity;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityCancelCommand extends ActivityCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('activity:cancel')
            ->setDescription('Cancel an activity')
            ->addArgument('id', InputArgument::OPTIONAL, 'The activity ID. Defaults to the most recent cancellable activity.')
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
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check recent activities on all environments (when selecting a default activity)');
        $this->addProjectOption()
            ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $input->getOption('all') || $input->getArgument('id'));

        /** @var ActivityLoader $loader */
        $loader = $this->getService('activity_loader');

        $executable = $this->config()->get('application.executable');

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
                $activity = $this->api()->matchPartialId($id, $loader->loadFromInput($apiResource, $input, 10, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel') ?: [], 'Activity');
                if (!$activity) {
                    $this->stdErr->writeln("Activity not found: <error>$id</error>");

                    return 1;
                }
            }
        } else {
            $activities = $loader->loadFromInput($apiResource, $input, 10, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel');
            if (\count($activities) === 0) {
                $this->stdErr->writeln('No cancellable activities found');

                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To list incomplete activities, run: <info>%s act -i</info>', $executable));

                return 1;
            }
            $choices = [];
            /** @var QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            $byId = [];
            $this->api()->sortResources($activities, 'created_at');
            foreach ($activities as $activity) {
                $byId[$activity->id] = $activity;
                $choices[$activity->id] = \sprintf(
                    '%s: %s (%s)',
                    $formatter->formatDate($activity->created_at),
                    ActivityMonitor::getFormattedDescription($activity),
                    ActivityMonitor::formatState($activity->state)
                );
            }
            $id = $questionHelper->choose($choices, 'Enter a number to choose an activity to cancel:', key($choices), true);
            $activity = $byId[$id];
        }

        $this->stdErr->writeln('Cancelling the activity ' . ActivityMonitor::getFormattedDescription($activity, true, true, 'cyan'));

        try {
            $activity->cancel();
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400 && \strpos($e->getMessage(), 'cannot be cancelled')) {
                if (\strpos($e->getMessage(), 'cannot be cancelled in its current state')) {
                    $activity->refresh();
                    $this->stdErr->writeln(\sprintf('The activity cannot be cancelled in its current state (<error>%s</error>).', $activity->id, $activity->state));
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
