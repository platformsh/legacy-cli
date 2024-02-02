<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Backups\RestoreOptions;
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
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Defaults to the most recent one')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, "The environment to restore to. Defaults to the backup's current environment")
            ->addOption('branch-from', null, InputOption::VALUE_REQUIRED, 'If the --target does not yet exist, this specifies the parent of the new environment')
            ->addOption('restore-code', null, InputOption::VALUE_NONE, 'Whether code should be restored as well as data');
        $this->addResourcesInitOption('parent');
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
        $project = $this->getSelectedProject();

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            $backup = $environment->getBackup($backupName);
            if (!$backup) {
                $this->stdErr->writeln("Backup not found: <error>$backupName</error>");

                return 1;
            }
        } else {
            $this->stdErr->writeln(\sprintf('Finding the most recent backup for the environment %s', $this->api()->getEnvironmentLabel($environment)));
            $backups = $environment->getBackups();
            $this->stdErr->writeln('');
            if (!$backups) {
                $this->stdErr->writeln("No backups found");

                return 1;
            }
            $backup = reset($backups);
        }

        if (!$backup->restorable) {
            $this->stdErr->writeln(\sprintf('The backup <error>%s</error> cannot be restored', $backup->id));

            return 1;
        }

        // Validate the --branch-from option.
        $branchFrom = $input->getOption('branch-from');
        if ($branchFrom !== null && !$this->api()->getEnvironment($branchFrom, $project)) {
            $this->stdErr->writeln(sprintf('Environment not found (in --branch-from): <error>%s</error>', $branchFrom));

            return 1;
        }

        // Validate the --resources-init option.
        $resourcesInit = $this->validateResourcesInitInput($input, $project);
        if ($resourcesInit === false) {
            return 1;
        }

        // Process the --target option, which does not have to be an existing environment.
        $target = $input->getOption('target');
        $targetEnvironment = $target !== null ? $this->api()->getEnvironment($target, $project) : $environment;
        $targetName = $target !== null ? $target : $environment->name;
        $targetLabel = $targetEnvironment
            ? $this->api()->getEnvironmentLabel($targetEnvironment)
            : '<info>' . $target . '</info>';

        // Do not allow restoring with --target on legacy regions: it can
        // overwrite the wrong branch. This is a (hopefully) temporary measure.
        if ($targetName !== $environment->name && \preg_match('#^us\.m#', $project->region)) {
            $this->stdErr->writeln('Backups cannot be automatically restored to another environment on this region: <comment>' . $project->region . '</comment>');
            $this->stdErr->writeln('Please contact support.');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $this->stdErr->writeln(\sprintf('Backup ID: <comment>%s</comment>', $backup->id));
        $this->stdErr->writeln(\sprintf('Created at: <comment>%s</comment>', $formatter->format($backup->created_at, 'created_at')));

        $differentTarget = $backup->environment !== $targetName;
        if ($differentTarget) {
            $original = $this->api()->getEnvironment($backup->environment, $project);
            $originalLabel = $original ? $this->api()->getEnvironmentLabel($original, 'comment') : '<comment>' . $backup->environment . '</comment>';
            $this->stdErr->writeln(\sprintf('Original environment: %s', $originalLabel));
            $this->stdErr->writeln('');
            if (!$questionHelper->confirm(\sprintf('Are you sure you want to restore this backup to the environment %s?', $targetLabel))) {
                return 1;
            }
        } else {
            $this->stdErr->writeln('');
            if (!$questionHelper->confirm('Are you sure you want to restore this backup?')) {
                return 1;
            }
        }
        $this->stdErr->writeln('');

        $this->stdErr->writeln("Restoring backup <info>$backup->id</info> to $targetLabel");

        $result = $backup->restore(
            (new RestoreOptions())
                ->setEnvironmentName($targetName)
                ->setBranchFrom($branchFrom)
                ->setRestoreCode($input->getOption('restore-code'))
                ->setResourcesInit($resourcesInit)
        );

        if ($this->shouldWait($input) && $result->countActivities()) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $project);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
