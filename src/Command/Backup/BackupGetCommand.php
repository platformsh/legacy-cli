<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:get', description: 'View an environment backup')]
class BackupGetCommand extends CommandBase
{
    public function __construct(private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Defaults to the most recent one.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The backup property to display.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        PropertyFormatter::configureInput($this->getDefinition());
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
        } else {
            $backups = $environment->getBackups();
            if (empty($backups)) {
                $this->stdErr->writeln('No backups found.');
                return 1;
            }
            $choices = [];
            $default = null;
            $byId = [];
            foreach ($backups as $backup) {
                $id = $backup->id;
                $byId[$id] = $backup;
                if (!isset($default)) {
                    $default = $backup->id;
                }
                $choices[$id] = sprintf('%s (%s)', $backup->id, $this->propertyFormatter->format($backup->created_at, 'created_at'));
            }
            $choice = $this->questionHelper->choose($choices, 'Enter a number to choose a backup:', $default);
            $backup = $byId[$choice];
        }

        $this->propertyFormatter->displayData($output, $backup->getProperties(), $input->getOption('property'));

        return 0;
    }
}
