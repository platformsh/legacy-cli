<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('snapshot:list')
            ->setAliases(['snapshots'])
            ->setDescription('List available snapshots of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of snapshots to list', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only snapshots created before this date will be listed');
        Table::configureInput($this->getDefinition());
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

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln("Finding snapshots for the environment <info>{$environment->id}</info>");
        }

        $activities = $environment->getActivities($input->getOption('limit'), 'environment.backup', $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No snapshots found');
            return 1;
        }

        $headers = ['Created', 'Snapshot name', 'Progress', 'State', 'Result'];
        $rows = [];
        foreach ($activities as $activity) {
            $snapshot_name = !empty($activity->payload['backup_name']) ? $activity->payload['backup_name'] : 'N/A';
            $rows[] = [
                date('Y-m-d H:i:s', strtotime($activity->created_at)),
                new AdaptiveTableCell($snapshot_name, ['wrap' => false]),
                $activity->getCompletionPercent() . '%',
                ActivityMonitor::formatState($activity->state),
                ActivityMonitor::formatResult($activity->result),
            ];
        }

        $table->render($rows, $headers);
        return 0;
    }
}
