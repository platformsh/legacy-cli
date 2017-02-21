<?php
namespace Platformsh\Cli\Command\Tunnel;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelCloseCommand extends TunnelCommandBase
{
    protected function configure()
    {
        $this
          ->setName('tunnel:close')
          ->setDescription('Close SSH tunnels')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Close all tunnels');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
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
                $this->stdErr->writeln('No tunnels found. Use --all to close all tunnels.');
                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

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
            if ($questionHelper->confirm($questionText)) {
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
