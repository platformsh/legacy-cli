<?php
namespace Platformsh\Cli\Command\Backup;

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

    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Required in non-interactive mode.');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->questionHelper;

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
            $choice = $questionHelper->choose($choices, 'Enter a number to choose a backup to delete:', null, false);
            $backup = $byId[$choice];
        }

        if (!$questionHelper->confirm(sprintf('Are you sure you want to delete the backup <comment>%s</comment>?', $this->labelBackup($backup)))) {
            return 1;
        }

        $result = $backup->delete();

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('The backup <info>%s</info> has been deleted.', $this->labelBackup($backup)));

        if ($this->shouldWait($input)) {
            /** @var ActivityMonitor $activityMonitor */
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return 0;
    }

    private function labelBackup(Backup $backup): string
    {
        /** @var PropertyFormatter $formatter */
        $formatter = $this->propertyFormatter;
        return sprintf('%s (%s)', $backup->id, $formatter->format($backup->created_at, 'created_at'));
    }
}
