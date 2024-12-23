<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Server;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:stop', description: 'Stop local project web server(s)')]
class ServerStopCommand extends ServerCommandBase
{
    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'Stop all servers');
    }

    public function isEnabled(): bool
    {
        if (!extension_loaded('posix')) {
            return false;
        }

        return parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();
        $all = $input->getOption('all');
        if (!$all && !$projectRoot) {
            throw new RootNotFoundException('Specify --all to stop all servers, or go to a project directory');
        }

        $servers = $this->getServerInfo();
        if (!$servers) {
            $this->stdErr->writeln('No servers are running');
            return 1;
        } elseif (!$all) {
            $servers = array_filter($servers, fn($server): bool => $server['projectRoot'] === $projectRoot);
            if (!$servers) {
                $this->stdErr->writeln('No servers are running for this project. Specify --all to stop all servers.');
                return 1;
            }
        }

        foreach ($servers as $address => $server) {
            $this->stdErr->writeln(sprintf('Stopping server at address %s, PID %s', $address, $server['pid']));
            $this->stopServer($address, $server['pid']);
        }

        return 0;
    }
}
