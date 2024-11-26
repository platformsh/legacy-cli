<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupGetCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('backup:get')
            ->setDescription('View an environment backup')
            ->addArgument('backup', InputArgument::OPTIONAL, 'The ID of the backup. Defaults to the most recent one.')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The backup property to display.');
        $this->addProjectOption()
             ->addEnvironmentOption();
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

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
                $choices[$id] = sprintf('%s (%s)', $backup->id, $formatter->format($backup->created_at, 'created_at'));
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $choice = $questionHelper->choose($choices, 'Enter a number to choose a backup:', $default);
            $backup = $byId[$choice];
        }

        $formatter->displayData($output, $backup->getProperties(), $input->getOption('property'));

        return 0;
    }
}
