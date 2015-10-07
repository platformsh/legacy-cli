<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('snapshot:list')
          ->setAliases(array('snapshots'))
          ->setDescription('List available snapshots of an environment')
          ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of snapshots to list', 10)
          ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only snapshots created before this date will be listed');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('List the most recent snapshots')
          ->addExample('List snapshots made before last week', "--start '1 week ago'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        $this->stdErr->writeln("Finding snapshots for the environment <info>{$environment['id']}</info>");
        $results = $environment->getActivities($input->getOption('limit'), 'environment.backup', $startsAt);
        if (!$results) {
            $this->stdErr->writeln('No snapshots found');
            return 1;
        }

        $headers = array("Created", "% Complete", "Snapshot name");
        $rows = array();
        foreach ($results as $result) {
            $payload = $result->getProperty('payload');
            $snapshot_name = !empty($payload['backup_name']) ? $payload['backup_name'] : 'N/A';
            $rows[] = array(
              date('Y-m-d H:i:s', strtotime($result->getProperty('created_at'))),
              $result->getCompletionPercent(),
              $snapshot_name,
            );
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        return 0;
    }
}
