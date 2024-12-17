<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:list', description: 'List available backups of an environment', aliases: ['backups'])]
class BackupListCommand extends CommandBase
{

    private array $tableHeader = [
        'created_at' => 'Created',
        'id' => 'Backup ID',
        'restorable' => 'Restorable',
        'automated' => 'Automated',
        'commit_id' => 'Commit ID',
        'expires_at' => 'Expires',
        'index' => 'Index',
        'live' => 'Live',
        'status' => 'Status',
        'updated_at' => 'Updated',
    ];
    private array $defaultColumns = ['created_at', 'id', 'restorable'];
    public function __construct(private readonly Api $api, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addHiddenOption('limit', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused')
            ->addHiddenOption('start', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->setHiddenAliases(['snapshots', 'snapshot:list']);
        $this->addExample('Display backups including the "live" and "commit_id" columns', '-c+live,commit_id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['limit', 'start']);
        $selection = $this->selector->getSelection($input);

        $environment = $selection->getEnvironment();

        $table = $this->table;
        $formatter = $this->propertyFormatter;

        $backups = $environment->getBackups((int) $input->getOption('limit'));
        if (!$backups) {
            $this->stdErr->writeln('No backups found');
            return 1;
        }

        $table->replaceDeprecatedColumns(['created' => 'created_at', 'name' => 'id'], $input, $output);
        $table->removeDeprecatedColumns(['progress', 'state', 'result'], '[deprecated]', $input, $output);

        $header = $this->tableHeader;
        $header['safe'] = 'Safe';
        $header['[deprecated]'] = '[Deprecated]';
        $rows = [];
        foreach ($backups as $backup) {
            $rows[] = [
                'created_at' => $formatter->format($backup->created_at, 'created_at'),
                'updated_at' => $formatter->format($backup->updated_at, 'updated_at'),
                'expires_at' => $formatter->format($backup->expires_at, 'expires_at'),
                'id' => new AdaptiveTableCell($backup->id, ['wrap' => false]),
                'name' => $backup->id,
                'commit_id' => $backup->commit_id,
                'live' => $formatter->format(!$backup->safe),
                'safe' => $formatter->format($backup->safe),
                'restorable' => $formatter->format($backup->restorable),
                'index' => $backup->index,
                'status' => $backup->status,
                'automated' =>  $formatter->format($backup->getProperty('automated', false, false), 'automated'),
                '[deprecated]' => '',
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Backups on the project %s, environment %s:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($environment)
            ));
        }

        $table->render($rows, $header, $this->defaultColumns);

        return 0;
    }
}
