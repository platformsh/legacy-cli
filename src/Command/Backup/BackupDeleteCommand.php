<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Backup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:delete', description: 'Delete an environment backup')]
class BackupDeleteCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Required in non-interactive mode.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $environment = $selection->getEnvironment();

        if ($id = $input->getArgument('backup')) {
            $backup = $environment->getBackup($id);
            if (!$backup) {
                $this->stdErr->writeln(sprintf('Backup not found: <error>%s</error>', $id));
                return 1;
            }
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('A backup ID is required in non-interactive mode.');
            return 1;
        } else {
            $backups = $environment->getBackups();
            if (empty($backups)) {
                $this->stdErr->writeln('No backups found.');
                return 1;
            }
            $choices = [];
            $byId = [];
            foreach ($backups as $backup) {
                $id = $backup->id;
                $byId[$id] = $backup;
                $choices[$id] = $this->labelBackup($backup);
            }
            $choice = $this->questionHelper->choose($choices, 'Enter a number to choose a backup to delete:', null, false);
            $backup = $byId[$choice];
        }

        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to delete the backup <comment>%s</comment>?', $this->labelBackup($backup)))) {
            return 1;
        }

        $result = $backup->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The backup <info>%s</info> has been deleted.', $this->labelBackup($backup)));

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return 0;
    }

    private function labelBackup(Backup $backup): string
    {
        return sprintf('%s (%s)', $backup->id, $this->propertyFormatter->format($backup->created_at, 'created_at'));
    }
}
