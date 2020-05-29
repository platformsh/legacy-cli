<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('backup:list')
            ->setAliases(['backups'])
            ->setDescription('List available backups of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of backups to list', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Deprecated option: no longer used');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->setHiddenAliases(['snapshots', 'snapshot:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $this->warnAboutDeprecatedOptions(['start']);

        $environment = $this->getSelectedEnvironment();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $backups = $environment->getBackups($input->getOption('limit'));

        $headers = [
            'created_at' => 'Created at',
            'id' => 'ID',
            'expires_at' => 'Expires at',
            'restorable' => 'Restorable',
            'commit' => 'Commit ID',
        ];
        $defaultColumns = ['created_at', 'id', 'restorable'];
        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                'id' => $backup->id,
                'created_at' => $formatter->formatDate($backup->created_at),
                'expires_at' => $backup->expires_at ? $formatter->formatDate($backup->expires_at) : '',
                'commit' => $backup->commit_id,
                'restorable' => $formatter->format($backup->restorable),
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Backups on the project %s, environment %s:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($environment)
            ));
        }

        $table->render($rows, $headers, $defaultColumns);

        return 0;
    }
}
