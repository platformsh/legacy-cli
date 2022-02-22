<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->setHiddenAliases(['snapshots', 'snapshot:list']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->warnAboutDeprecatedOptions(['start']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $backups = $environment->getBackups($input->getOption('limit'));
        if (!$backups) {
            $this->stdErr->writeln('No backups found');
            return 1;
        }

        $table->replaceDeprecatedColumns(['created' => 'created_at', 'name' => 'id'], $input, $output);
        $table->removeDeprecatedColumns(['progress', 'state', 'result'], '[deprecated]', $input, $output);

        $headers = [
            'created_at' => 'Created',
            'expires_at' => 'Expires',
            'id' => 'Backup ID',
            'status' => 'Status',
            'commit_id' => 'Commit ID',
            'safe' => 'Safe',
            'restorable' => 'Restorable',
            'index' => 'Index',
            '[deprecated]' => '[Deprecated]',
        ];
        $defaultColumns = ['created_at', 'id', 'restorable'];
        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                'created_at' => $formatter->format($backup->created_at, 'created_at'),
                'updated_at' => $formatter->format($backup->updated_at, 'updated_at'),
                'expires_at' => $formatter->format($backup->expires_at, 'expires_at'),
                'id' => new AdaptiveTableCell($backup->id, ['wrap' => false]),
                'name' => $backup->id,
                'commit_id' => $backup->commit_id,
                'safe' => $formatter->format($backup->safe),
                'restorable' => $formatter->format($backup->restorable),
                'index' => $backup->index,
                'status' => $backup->status,
                '[deprecated]' => '',
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
