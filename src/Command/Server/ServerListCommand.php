<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:list', description: 'List running local project web server(s)', aliases: ['servers'])]
class ServerListCommand extends ServerCommandBase
{
    public function __construct(private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'List all servers');
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servers = $this->getServerInfo();
        if (!$servers) {
            $this->stdErr->writeln('No servers are running');
            return 1;
        }

        $projectRoot = $this->selector->getProjectRoot();
        $all = $input->getOption('all');
        if (!$all && $projectRoot) {
            $servers = array_filter($servers, fn($server): bool => $server['projectRoot'] === $projectRoot);
            if (!$servers) {
                $this->stdErr->writeln('No servers are running for this project. Specify --all to view all servers.');
                return 1;
            }
        }
        $headers = ['Address', 'PID', 'App', 'Project root', 'Log'];
        $rows = [];
        foreach ($servers as $address => $server) {
            $row = [$address, (string) $server['pid'], $server['appId'], $server['projectRoot']];
            $logFile = ltrim(str_replace($server['projectRoot'], '', $server['logFile']), '/');
            $row[] = $logFile;
            $rows[] = $row;
        }
        $this->table->render($rows, $headers);

        return 0;
    }
}
