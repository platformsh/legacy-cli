<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupRestoreCommand extends CommandBase
{
    protected static $defaultName = 'snapshot:restore';

    private $activityService;
    private $api;
    private $loader;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityLoader $activityLoader,
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->loader = $activityLoader;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Restore an environment backup')
            ->addArgument('backup', InputArgument::OPTIONAL, 'The name of the backup. Defaults to the most recent one')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, "The environment to restore to. Defaults to the backup's current environment")
            ->addOption('branch-from', null, InputOption::VALUE_REQUIRED, 'If the --target does not yet exist, this specifies the parent of the new environment');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->setHiddenAliases(['environment:restore', 'snapshot:restore']);

        $this->addExample('Restore the most recent backup');
        $this->addExample('Restore a specific backup', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $environment = $selection->getEnvironment();

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            $backupActivities = $this->loader->load($environment, null, ['environment.backup'], null, 'complete', null, function (Activity $activity) use ($backupName) {
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
        if ($branchFrom !== null && !$this->api->getEnvironment($branchFrom, $selection->getProject())) {
            $this->stdErr->writeln(sprintf('Environment not found (in --branch-from): <error>%s</error>', $branchFrom));

            return 1;
        }

        // Process the --target option.
        $target = $input->getOption('target');
        $targetEnvironment = $target !== null
            ? $this->api->getEnvironment($target, $selection->getProject())
            : $environment;
        $targetLabel = $targetEnvironment
            ? $this->api->getEnvironmentLabel($targetEnvironment)
            : '<info>' . $target . '</info>';

        // Do not allow restoring with --target on legacy regions: it can
        // overwrite the wrong branch. This is a (hopefully) temporary measure.
        $region = $selection->getProject()->region;
        if ((!$targetEnvironment || $targetEnvironment->id !== $environment->id)
            && \preg_match('#^(eu|us|bc)\.[pm]#', $region)) {
            $this->stdErr->writeln('Backups cannot be automatically restored to another environment on this region: <comment>' . $region . '</comment>');
            $this->stdErr->writeln('Please contact support.');

            return 1;
        }

        $name = $selectedActivity['payload']['backup_name'];
        $date = date('c', strtotime($selectedActivity['created_at']));
        if (!$this->questionHelper->confirm(
            "Are you sure you want to restore the backup <comment>$name</comment> from <comment>$date</comment> to environment $targetLabel?"
        )) {
            return 1;
        }

        $this->stdErr->writeln("Restoring backup <info>$name</info> to $targetLabel");

        $activity = $selectedActivity->restore($target, $branchFrom);
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitAndLog($activity);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
