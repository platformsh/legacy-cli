<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:backup')
          ->setDescription('Make a backup (snapshot) of an environment')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to back up')
          ->addOption('list', 'l', InputOption::VALUE_NONE, 'List backups')
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the backup to complete');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->setHelp(
          "See https://docs.platform.sh/use-platform/backup-and-restore.html\n\n"
          . "<comment>Examples:</comment>\n"
          . "\nBack up the current environment:\n  <info>platform %command.name%</info>"
          . "\nList available backups:\n  <info>platform %command.name% --list</info>"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if ($input->getOption('list')) {
            return $this->listBackups($output);
        }

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];
        if (!$selectedEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
              "Operation not available: the environment <error>$environmentId</error> cannot be backed up"
            );

            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $this->stdErr->writeln("Backing up <info>$environmentId</info>");

        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
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

        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $stdErr->writeln("Finding backups for the environment <info>{$environment['id']}</info>");
        $results = $environment->getActivities(10, 'environment.backup');
        if (!$results) {
            $stdErr->writeln('No backups found');
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
