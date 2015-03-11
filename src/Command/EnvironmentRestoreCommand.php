<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRestoreCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:restore')
            ->setDescription('Restore the most recent environment backup')
            ->addArgument('backup', InputArgument::OPTIONAL, 'The name of the backup. Defaults to the most recent one');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        $backupName = $input->getArgument('backup');
        $startsAt = strtotime('1 week ago');
        if (!empty($backupName)) {
            // Find the specified backup.
            $backupActivities = $environment->getActivities(0, 'environment.backup', $startsAt);
            foreach ($backupActivities as $activity) {
                $payload = $activity->getProperty('payload');
                if ($payload['backup_name'] == $backupName) {
                    $selectedActivity = $activity;
                    break;
                }
            }
            if (empty($selectedActivity)) {
                $output->writeln("Backup not found: <error>$backupName</error>");
                return 1;
            }
        }
        else {
            // Find the most recent backup.
            $environmentId = $this->environment['id'];
            $output->writeln("Finding the most recent backup for the environment <info>$environmentId</info>");
            $backupActivities = $environment->getActivities(1, 'environment.backup', $startsAt);
            if (!$backupActivities) {
                $output->writeln("No backups found");
                return 1;
            }
            /** @var \CommerceGuys\Platform\Cli\Model\Activity $selectedActivity */
            $selectedActivity = reset($backupActivities);
        }

        if (!$selectedActivity->operationAvailable('restore')) {
            if (!$selectedActivity->isComplete()) {
                $output->writeln("The backup is not complete, so it cannot be restored");
            }
            else {
                $output->writeln("The backup cannot be restored");
            }
            return 1;
        }

        /** @var \CommerceGuys\Platform\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $payload = $selectedActivity->getProperty('payload');
        $name = $payload['backup_name'];
        $environmentId = $this->environment['id'];
        $date = $selectedActivity->getPropertyFormatted('created_at');
        if (!$questionHelper->confirm("Are you sure you want to restore the backup <comment>$name</comment> from <comment>$date</comment>?", $input, $output)) {
            return 1;
        }

        $output->writeln("Restoring backup <info>$name</info>");
        $selectedActivity->restore();

        return 0;
    }
}
