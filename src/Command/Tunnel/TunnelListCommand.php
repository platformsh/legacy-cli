<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelListCommand extends TunnelCommandBase
{
    protected function configure()
    {
        $this
          ->setName('tunnel:list')
          ->setAliases(['tunnels'])
          ->setDescription('List SSH tunnels')
          ->addOption('all', 'a', InputOption::VALUE_NONE, 'View all tunnels');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkSupport();
        $tunnels = $this->getTunnelInfo();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $tunnels = $this->filterTunnels($tunnels, $input);
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found. Use --all to view all tunnels.');
                return 1;
            }
        }

        $table = new Table($input, $output);
        $headers = ['Port', 'Project', 'Environment', 'App', 'Relationship'];
        $rows = [];
        foreach ($tunnels as $tunnel) {
            $rows[] = [
                $tunnel['localPort'],
                $tunnel['projectId'],
                $tunnel['environmentId'],
                $tunnel['appName'] ?: '[default]',
                $this->formatTunnelRelationship($tunnel),
            ];
        }
        $table->render($rows, $headers);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');

            if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
                $this->stdErr->writeln('List all tunnels with: <info>' . self::$config->get('application.executable') . ' tunnel:list --all</info>');
            }

            $this->stdErr->writeln("View tunnel details with: <info>" . self::$config->get('application.executable') . " tunnel:info</info>");
            $this->stdErr->writeln("Close tunnels with: <info>" . self::$config->get('application.executable') . " tunnel:close</info>");
        }

        return 0;
    }
}
