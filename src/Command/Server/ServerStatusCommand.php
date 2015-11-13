<?php
namespace Platformsh\Cli\Command\Server;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServerStatusCommand extends ServerCommandBase
{
    protected function configure()
    {
        $this
          ->setName('server:status')
          ->setDescription('Check the status of local project web server(s)')
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check all servers');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $servers = $this->getServerInfo();
        if (!$servers) {
            $this->stdErr->writeln('No servers are running');
            return 1;
        }

        $projectRoot = $this->getProjectRoot();
        $all = $input->getOption('all');
        if (!$all && $projectRoot) {
            $servers = array_filter($servers, function ($server) use ($projectRoot) {
                return $server['projectRoot'] === $projectRoot;
            });
            if (!$servers) {
                $this->stdErr->writeln('No servers are running for this project. Specify --all to view all servers.');
                return 1;
            }
        }

        $table = new Table($output);
        $headers = ['Address', 'PID', 'App', 'Project root', 'Log'];
        $table->setHeaders($headers);
        foreach ($servers as $address => $server) {
            $row = [$address, $server['pid'], $server['appId'], $server['projectRoot']];
            $logFile = ltrim(str_replace($server['projectRoot'], '', $server['logFile']), '/');
            $row[] = $logFile;
            $table->addRow($row);
        }
        $table->render();

        return 0;
    }
}
