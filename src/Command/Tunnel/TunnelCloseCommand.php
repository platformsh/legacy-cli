<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\TunnelManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tunnel:close', description: 'Close SSH tunnels')]
class TunnelCloseCommand extends TunnelCommandBase
{
    public function __construct(private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly TunnelManager $tunnelManager)
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
        $tunnels = $this->tunnelManager->getTunnels();
        $allTunnelsCount = count($tunnels);
        if (!$allTunnelsCount) {
            $this->stdErr->writeln('No tunnels found.');
            return 1;
        }

        // Filter tunnels according to the current project and environment, if
        // available.
        if (!$input->getOption('all')) {
            $tunnels = $this->tunnelManager->filterBySelection($tunnels, $this->selector->getSelection($input));
            if (!count($tunnels)) {
                $this->stdErr->writeln('No tunnels found. Use --all to close all tunnels.');
                return 1;
            }
        }

        $error = false;
        foreach ($tunnels as $tunnel) {
            $relationshipString = $this->tunnelManager->formatRelationship($tunnel);
            $appString = $tunnel->metadata['projectId'] . '-' . $tunnel->metadata['environmentId'];
            if ($tunnel->metadata['appName']) {
                $appString .= '--' . $tunnel->metadata['appName'];
            }
            $questionText = sprintf(
                'Close tunnel to relationship <comment>%s</comment> on %s?',
                $relationshipString,
                $appString,
            );
            if ($this->questionHelper->confirm($questionText)) {
                $this->tunnelManager->close($tunnel);
            }
        }

        if (!$input->getOption('all') && count($tunnels) < $allTunnelsCount) {
            $this->stdErr->writeln('Use --all to close all tunnels.');
        }

        return 0;
    }
}
