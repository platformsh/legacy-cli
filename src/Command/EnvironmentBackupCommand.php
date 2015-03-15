<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Activity;
use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBackupCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:backup')
            ->setDescription('Make a backup of an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to back up')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List backups')
            ->addOption(
              'no-wait',
              null,
              InputOption::VALUE_NONE,
              "Do not wait for the operation to complete"
            );
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];
        if ($input->getOption('list')) {
            return $this->listBackups($output);
        }

        if (!$this->operationAvailable('backup')) {
            $output->writeln(
              "Operation not available: the environment <error>$environmentId</error> cannot be backed up"
            );
            return 1;
        }

        $output->writeln("Backing up environment <info>$environmentId</info>");
        $client = $this->getPlatformClient($this->environment['endpoint']);
        $data = $client->backupEnvironment();
        if (!$input->getOption('no-wait')) {
            $success = Activity::waitAndLog(
              $data,
              $client,
              $output,
              "A backup of environment <info>$environmentId</info> has been created",
              "The backup failed"
            );
            if ($success === false) {
                return 1;
            }
        }

        if (!empty($data['_embedded']['activities'][0]['payload']['backup_name'])) {
            $name = $data['_embedded']['activities'][0]['payload']['backup_name'];
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
        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        $output->writeln("Finding backups for the environment <info>{$this->environment['id']}</info>");
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
              $result->id(),
              $result->getPropertyFormatted('created_at'),
              $result->getPropertyFormatted('completion_percent'),
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
