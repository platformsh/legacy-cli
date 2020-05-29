<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupRestoreCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('backup:restore')
            ->setDescription('Restore an environment backup')
            ->addArgument('backup', InputArgument::OPTIONAL, 'The name of the backup. Defaults to the most recent one')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, "The environment to restore to. Defaults to the backup's current environment")
            ->addOption('branch-from', null, InputOption::VALUE_REQUIRED, 'If the --target does not yet exist, this specifies the parent of the new environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->setHiddenAliases(['environment:restore', 'snapshot:restore']);
        $this->addExample('Restore the most recent backup');
        $this->addExample('Restore a specific backup', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            $backupActivities = $loader->load($environment, null, 'environment.backup', null, function (Activity $activity) use ($backupName) {
                return $activity->payload['backup_name'] === $backupName;
            });
            // Find the specified backup.
            foreach (\array_reverse($backupActivities) as $activity) {
                if ($activity['payload']['backup_name'] == $backupName) {
                    $selectedActivity = $activity;
                    break;
                }
            }
            if (empty($selectedActivity)) {
                $this->stdErr->writeln("Backup not found: <error>$backupName</error>");

                return 1;
            }
        } else {
            // Find the most recent backup.
            $environmentId = $environment->id;
            $this->stdErr->writeln("Finding the most recent backup for the environment <info>$environmentId</info>");
            $backupActivities = $environment->getActivities(1, 'environment.backup');
            $backupActivities = array_filter($backupActivities, function (Activity $activity) {
                return $activity->result === Activity::RESULT_SUCCESS;
            });
            if (!$backupActivities) {
                $this->stdErr->writeln("No backups found");

                return 1;
            }
            /** @var \Platformsh\Client\Model\Activity $selectedActivity */
            $selectedActivity = reset($backupActivities);
        }

        if (!$selectedActivity->operationAvailable('restore', true)) {
            if (!$selectedActivity->isComplete()) {
                $this->stdErr->writeln("The backup is not complete, so it cannot be restored");
            } else {
                $this->stdErr->writeln("The backup cannot be restored");
            }

            return 1;
        }

        // Validate the --branch-from option.
        $branchFrom = $input->getOption('branch-from');
        if ($branchFrom !== null && !$this->api()->getEnvironment($branchFrom, $this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('Environment not found (in --branch-from): <error>%s</error>', $branchFrom));

            return 1;
        }

        // Process the --target option.
        $target = $input->getOption('target');
        $targetEnvironment = $target !== null
            ? $this->api()->getEnvironment($target, $this->getSelectedProject())
            : $environment;
        $targetLabel = $targetEnvironment
            ? $this->api()->getEnvironmentLabel($targetEnvironment)
            : '<info>' . $target . '</info>';

        // Do not allow restoring with --target on legacy regions: it can
        // overwrite the wrong branch. This is a (hopefully) temporary measure.
        if ((!$targetEnvironment || $targetEnvironment->id !== $environment->id)
            && preg_match('#https://(eu|us)\.[pm]#', $this->getSelectedProject()->getUri())) {
            $this->stdErr->writeln('Backups cannot be automatically restored to another environment on this region.');
            $this->stdErr->writeln('Please contact support.');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $name = $selectedActivity['payload']['backup_name'];
        $date = date('c', strtotime($selectedActivity['created_at']));
        if (!$questionHelper->confirm(
            "Are you sure you want to restore the backup <comment>$name</comment> from <comment>$date</comment> to environment $targetLabel?"
        )) {
            return 1;
        }

        $this->stdErr->writeln("Restoring backup <info>$name</info> to $targetLabel");

        $activity = $selectedActivity->restore($target, $branchFrom);
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
