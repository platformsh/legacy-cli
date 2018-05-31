<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotRestoreCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:restore';

    private $activityService;
    private $api;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Restore an environment snapshot')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'The name of the snapshot. Defaults to the most recent one');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->setHiddenAliases(['environment:restore']);
        $this->addExample('Restore the most recent snapshot');
        $this->addExample('Restore a specific snapshot', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

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
            $snapshotActivities = array_filter($snapshotActivities, function (Activity $activity) {
                return $activity->result === Activity::RESULT_SUCCESS;
            });
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

        $name = $selectedActivity['payload']['backup_name'];
        $date = date('c', strtotime($selectedActivity['created_at']));
        if (!$this->questionHelper->confirm(
            "Are you sure you want to restore the snapshot <comment>$name</comment> from <comment>$date</comment>?"
        )) {
            return 1;
        }

        $this->stdErr->writeln("Restoring snapshot <info>$name</info>");

        $activity = $selectedActivity->restore();
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitAndLog(
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
