<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotRestoreCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('snapshot:restore')
            ->setDescription('Restore an environment snapshot')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'The name of the snapshot. Defaults to the most recent one');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->setHiddenAliases(['environment:restore']);
        $this->addExample('Restore the most recent snapshot');
        $this->addExample('Restore a specific snapshot', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $snapshotName = $input->getArgument('snapshot');
        if (!empty($snapshotName)) {
            // Find the specified snapshot.
            $snapshotActivities = $environment->getActivities(0, 'environment.backup');
            foreach ($snapshotActivities as $activity) {
                if ($activity['payload']['backup_name'] == $snapshotName) {
                    $selectedActivity = $activity;
                    break;
                }
            }
            if (empty($selectedActivity)) {
                $this->stdErr->writeln("Snapshot not found: <error>$snapshotName</error>");

                return 1;
            }
        } else {
            // Find the most recent snapshot.
            $environmentId = $environment->id;
            $this->stdErr->writeln("Finding the most recent snapshot for the environment <info>$environmentId</info>");
            $snapshotActivities = $environment->getActivities(1, 'environment.backup');
            if (!$snapshotActivities) {
                $this->stdErr->writeln("No snapshots found");

                return 1;
            }
            /** @var \Platformsh\Client\Model\Activity $selectedActivity */
            $selectedActivity = reset($snapshotActivities);
        }

        if (!$selectedActivity->operationAvailable('restore')) {
            if (!$selectedActivity->isComplete()) {
                $this->stdErr->writeln("The snapshot is not complete, so it cannot be restored");
            } else {
                $this->stdErr->writeln("The snapshot cannot be restored");
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $name = $selectedActivity['payload']['backup_name'];
        $date = date('Y-m-d H:i', strtotime($selectedActivity['created_at']));
        if (!$questionHelper->confirm(
            "Are you sure you want to restore the snapshot <comment>$name</comment> from <comment>$date</comment>?"
        )) {
            return 1;
        }

        $this->stdErr->writeln("Restoring snapshot <info>$name</info>");

        $activity = $selectedActivity->restore();
        if (!$input->getOption('no-wait')) {
            $this->stdErr->writeln('Waiting for the restore to complete...');
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitAndLog(
                $activity,
                'The snapshot was successfully restored',
                'Restoring failed'
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
