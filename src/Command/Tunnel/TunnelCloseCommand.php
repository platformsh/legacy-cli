<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tunnel:close', description: 'Close SSH tunnels')]
class TunnelCloseCommand extends TunnelCommandBase
{
    public function __construct(private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Close all tunnels');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
                $this->stdErr->writeln('No tunnels found. Use --all to close all tunnels.');
                return 1;
            }
        }

        $error = false;
        foreach ($tunnels as $tunnel) {
            $relationshipString = $this->formatTunnelRelationship($tunnel);
            $appString = $tunnel['projectId'] . '-' . $tunnel['environmentId'];
            if ($tunnel['appName']) {
                $appString .= '--' . $tunnel['appName'];
            }
            $questionText = sprintf(
                'Close tunnel to relationship <comment>%s</comment> on %s?',
                $relationshipString,
                $appString
            );
            if ($this->questionHelper->confirm($questionText)) {
                if ($this->closeTunnel($tunnel)) {
                    $this->stdErr->writeln(sprintf(
                        'Closed tunnel to <info>%s</info> on %s',
                        $relationshipString,
                        $appString
                    ));
                } else {
                    $error = true;
                    $this->stdErr->writeln(sprintf(
                        'Failed to close tunnel to <error>%s</error> on %s',
                        $relationshipString,
                        $appString
                    ));
                }
            }
        }

        if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
            $this->stdErr->writeln('Use --all to close all tunnels.');
        }

        return $error ? 1 : 0;
    }
}
