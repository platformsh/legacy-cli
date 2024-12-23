<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Backups\RestoreOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:restore', description: 'Restore an environment backup')]
class BackupRestoreCommand extends CommandBase
{
    /** @var string[] */
    private array $validResourcesInitOptions = ['backup', 'parent', 'default', 'minimum'];

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Defaults to the most recent one')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, "The environment to restore to. Defaults to the backup's current environment")
            ->addOption('branch-from', null, InputOption::VALUE_REQUIRED, 'If the --target does not yet exist, this specifies the parent of the new environment')
            ->addOption('no-code', null, InputOption::VALUE_NONE, 'Do not restore code, only data.')
            ->addHiddenOption('restore-code', null, InputOption::VALUE_NONE, '[DEPRECATED] This option no longer has an effect.');
        if ($this->config->getBool('api.sizing')) {
            $this->addOption('no-resources', null, InputOption::VALUE_NONE, "Do not override the target's existing resource settings.");
            $this->resourcesUtil->addOption($this->getDefinition(), $this->validResourcesInitOptions);
        }
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->setHiddenAliases(['environment:restore', 'snapshot:restore']);
        $this->addExample('Restore the most recent backup');
        $this->addExample('Restore a specific backup', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['restore-code']);

        $selection = $this->selector->getSelection($input);

        $environment = $selection->getEnvironment();
        $project = $selection->getProject();

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            $backup = $environment->getBackup($backupName);
            if (!$backup) {
                $this->stdErr->writeln("Backup not found: <error>$backupName</error>");

                return 1;
            }
        } else {
            $this->stdErr->writeln(\sprintf('Finding the most recent backup for the environment %s', $this->api->getEnvironmentLabel($environment)));
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
        if ($branchFrom !== null && !$this->api->getEnvironment($branchFrom, $project)) {
            $this->stdErr->writeln(sprintf('Environment not found (in --branch-from): <error>%s</error>', $branchFrom));

            return 1;
        }

        // Validate the --resources-init option.
        $resourcesInit = $this->resourcesUtil->validateInput($input, $project, $this->validResourcesInitOptions);
        if ($resourcesInit === false) {
            return 1;
        }

        // Process the --target option, which does not have to be an existing environment.
        $target = $input->getOption('target');
        $targetEnvironment = $target !== null ? $this->api->getEnvironment($target, $project) : $environment;
        $targetName = $target !== null ? $target : $environment->name;
        $targetLabel = $targetEnvironment
            ? $this->api->getEnvironmentLabel($targetEnvironment)
            : '<info>' . $target . '</info>';

        // Display a summary of the backup.
        $this->stdErr->writeln(\sprintf('Backup ID: <comment>%s</comment>', $backup->id));
        $this->stdErr->writeln(\sprintf('Created at: <comment>%s</comment>', $this->propertyFormatter->format($backup->created_at, 'created_at')));
        if ($input->getOption('no-code')) {
            $this->stdErr->writeln('Only data, not code, will be restored.');
        }

        $differentTarget = $backup->environment !== $targetName;
        if ($differentTarget) {
            $original = $this->api->getEnvironment($backup->environment, $project);
            $originalLabel = $original ? $this->api->getEnvironmentLabel($original, 'comment') : '<comment>' . $backup->environment . '</comment>';
            $this->stdErr->writeln(\sprintf('Original environment: %s', $originalLabel));
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to restore this backup to the environment %s?', $targetLabel))) {
                return 1;
            }
        } else {
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm('Are you sure you want to restore this backup?')) {
                return 1;
            }
        }
        $this->stdErr->writeln('');

        $this->stdErr->writeln("Restoring backup <info>$backup->id</info> to $targetLabel");

        $result = $backup->restore(
            (new RestoreOptions())
                ->setEnvironmentName($targetName)
                ->setBranchFrom($branchFrom)
                ->setRestoreCode($input->getOption('no-code') ? false : null)
                ->setRestoreResources($input->hasOption('no-resources') && $input->getOption('no-resources') ? false : null)
                ->setResourcesInit($resourcesInit),
        );

        if ($this->activityMonitor->shouldWait($input) && $result->countActivities()) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $project);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
