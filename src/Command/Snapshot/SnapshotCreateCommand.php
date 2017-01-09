<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotCreateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('snapshot:create')
            ->setDescription('Make a snapshot of an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption('Do not wait for the snapshot to complete');
        $this->setHiddenAliases(['backup', 'environment:backup']);
        $this->addExample('Make a snapshot of the current environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment->id;
        if (!$selectedEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
                "Operation not available: cannot create a snapshot of <error>$environmentId</error>"
            );
            if ($selectedEnvironment->is_dirty) {
                $this->api()->clearEnvironmentsCache($selectedEnvironment->project);
            }

            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $this->stdErr->writeln("Creating a snapshot of <info>$environmentId</info>");

        if (!$input->getOption('no-wait')) {
            $this->stdErr->writeln('Waiting for the snapshot to complete...');
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitAndLog(
                $activity,
                'A snapshot of environment <info>' . $environmentId . '</info> has been created',
                'The snapshot failed'
            );
            if (!$success) {
                return 1;
            }
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Snapshot name: <info>$name</info>");
        }

        return 0;
    }
}
