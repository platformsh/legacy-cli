<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\TunnelManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tunnel:list', description: 'List SSH tunnels', aliases: ['tunnels'])]
class TunnelListCommand extends TunnelCommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
        'port' => 'Port',
        'project' => 'Project',
        'environment' => 'Environment',
        'app' => 'App',
        'relationship' => 'Relationship',
        'url' => 'URL',
    ];

    /** @var string[] */
    protected array $defaultColumns = ['Port', 'Project', 'Environment', 'App', 'Relationship'];

    public function __construct(private readonly Config $config, private readonly Selector $selector, private readonly Table $table, private readonly TunnelManager $tunnelManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'View all tunnels');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tunnels = $this->tunnelManager->getTunnels();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        $executable = $this->config->getStr('application.executable');

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $selection = $this->selector->getSelection($input);
            $tunnels = $this->tunnelManager->filterBySelection($tunnels, $selection);
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found.');
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable,
                ));

                return 1;
            }
        }

        $rows = [];
        foreach ($tunnels as $tunnel) {
            $rows[] = [
                'port' => $tunnel->localPort,
                'project' => $tunnel->metadata['projectId'],
                'environment' => $tunnel->metadata['environmentId'],
                'app' => $tunnel->metadata['appName'] ?: '[default]',
                'relationship' => $this->tunnelManager->formatRelationship($tunnel),
                'url' => $this->tunnelManager->getUrl($tunnel),
            ];
        }
        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');

            if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable,
                ));
            }

            $this->stdErr->writeln([
                "View tunnel details with: <info>$executable tunnel:info</info>",
                "Close tunnels with: <info>$executable tunnel:close</info>",
            ]);
        }

        return 0;
    }
}
