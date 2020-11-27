<?php
namespace Platformsh\Cli\Command\Activity;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityCancelCommand extends CommandBase
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
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (when selecting a default activity)')
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
            $activities = $loader->loadFromInput($apiResource, $input, 1, [Activity::STATE_PENDING, Activity::STATE_IN_PROGRESS], 'cancel');
            if ($activities === false) {
                return 1;
            }
            /** @var Activity $activity */
            $activity = reset($activities);
            if (!$activity) {
                $this->stdErr->writeln('No cancellable activities found');

                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To list incomplete activities, run: <info>%s act -i</info>', $executable));

                return 1;
            }
        }

        $this->stdErr->writeln(sprintf(
            'Cancelling the activity <info>%s</info> (%s)...',
            $activity->id,
            ActivityMonitor::getFormattedDescription($activity)
        ));

        try {
            $activity->cancel();
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400 && \strpos($e->getMessage(), 'cannot be cancelled in its current state')) {
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
