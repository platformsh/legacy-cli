<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:backup')
          ->setDescription('Make a backup of an environment')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to back up')
          ->addOption('list', 'l', InputOption::VALUE_NONE, 'List backups')
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the backup to complete');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, $output);

        if ($input->getOption('list')) {
            return $this->listBackups($output);
        }

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];
        if (!$selectedEnvironment->operationAvailable('backup')) {
            $output->writeln(
              "Operation not available: the environment <error>$environmentId</error> cannot be backed up"
            );

            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $output->writeln("Backing up <info>$environmentId</info>");

        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $output,
              "A backup of environment <info>$environmentId</info> has been created",
              "The backup failed"
            );
            if (!$success) {
                return 1;
            }
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Backup name: <info>$name</info>");
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function listBackups(OutputInterface $output)
    {
        $environment = $this->getSelectedEnvironment();

        $output->writeln("Finding backups for the environment <info>{$environment['id']}</info>");
        $results = $environment->getActivities(10, 'environment.backup');
        if (!$results) {
            $output->writeln('No backups found');
            return 1;
        }

        $headers = array("Activity ID", "Created", "% Complete", "Backup name");
        $rows = array();
        foreach ($results as $result) {
            $payload = $result->getProperty('payload');
            $backup_name = !empty($payload['backup_name']) ? $payload['backup_name'] : 'N/A';
            $rows[] = array(
              $result->getProperty('id'),
              date('Y-m-d H:i:s', strtotime($result->getProperty('created_at'))),
              $result->getCompletionPercent(),
              $backup_name,
            );
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        return 0;
    }
}
