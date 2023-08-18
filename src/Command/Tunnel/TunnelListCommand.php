<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelListCommand extends TunnelCommandBase
{
    protected $tableHeader = [
        'port' => 'Port',
        'project' => 'Project',
        'environment' => 'Environment',
        'app' => 'App',
        'relationship' => 'Relationship',
        'url' => 'URL',
    ];
    protected $defaultColumns = ['Port', 'Project', 'Environment', 'App', 'Relationship'];

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
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tunnels = $this->getTunnelInfo();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        $executable = $this->config()->get('application.executable');

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $tunnels = $this->filterTunnels($tunnels, $input);
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found.');
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable
                ));

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $rows = [];
        foreach ($tunnels as $tunnel) {
            $rows[] = [
                'port' => $tunnel['localPort'],
                'project' => $tunnel['projectId'],
                'environment' => $tunnel['environmentId'],
                'app' => $tunnel['appName'] ?: '[default]',
                'relationship' => $this->formatTunnelRelationship($tunnel),
                'url' => $this->getTunnelUrl($tunnel, $tunnel['service']),
            ];
        }
        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');

            if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
                $this->stdErr->writeln(sprintf(
                    'List all tunnels with: <info>%s tunnels --all</info>',
                    $executable
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
