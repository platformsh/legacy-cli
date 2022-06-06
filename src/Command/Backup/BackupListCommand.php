<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupListCommand extends CommandBase
{
    protected static $defaultName = 'backup:list|backups';
    protected static $defaultDescription = 'List available backups of an environment';

    private $api;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
        $this->setHiddenAliases(['snapshots', 'snapshot:list']);
    }

    protected function configure()
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, '[Deprecated] - this option is unused');
        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO
        //$this->warnAboutDeprecatedOptions(['start']);
        $selection = $this->selector->getSelection($input);

        $environment = $selection->getEnvironment();

        $backups = $environment->getBackups($input->getOption('limit'));
        if (!$backups) {
            $this->stdErr->writeln('No backups found');
            return 1;
        }

        /**
         * @todo this is a workaround for an API bug where backups are sorted by ID - remove this when the API is fixed
         */
        Api::sortResources($backups, 'created_at', true);

        $this->table->replaceDeprecatedColumns(['created' => 'created_at', 'name' => 'id'], $input, $output);
        $this->table->removeDeprecatedColumns(['progress', 'state', 'result'], '[deprecated]', $input, $output);

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
                'created_at' => $this->formatter->format($backup->created_at, 'created_at'),
                'updated_at' => $this->formatter->format($backup->updated_at, 'updated_at'),
                'expires_at' => $this->formatter->format($backup->expires_at, 'expires_at'),
                'id' => new AdaptiveTableCell($backup->id, ['wrap' => false]),
                'name' => $backup->id,
                'commit_id' => $backup->commit_id,
                'safe' => $this->formatter->format($backup->safe),
                'restorable' => $this->formatter->format($backup->restorable),
                'index' => $backup->index,
                'status' => $backup->status,
                '[deprecated]' => '',
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Backups on the project %s, environment %s:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($environment)
            ));
        }

        $this->table->render($rows, $headers, $defaultColumns);

        return 0;
    }
}
