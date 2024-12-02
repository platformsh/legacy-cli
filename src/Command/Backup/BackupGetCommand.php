<?php
namespace Platformsh\Cli\Command\Backup;

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

    public function __construct(private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
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

        /** @var PropertyFormatter $formatter */
        $formatter = $this->propertyFormatter;

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
            /** @var QuestionHelper $questionHelper */
            $questionHelper = $this->questionHelper;
            $choice = $questionHelper->choose($choices, 'Enter a number to choose a backup:', $default);
            $backup = $byId[$choice];
        }

        $formatter->displayData($output, $backup->getProperties(), $input->getOption('property'));

        return 0;
    }
}
