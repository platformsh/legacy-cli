<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Console\ProcessManager;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelSingleCommand extends TunnelCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('tunnel:single')
            ->setDescription('Open a single SSH tunnel to an app relationship')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The local port');
        $this->addOption('gateway-ports', 'g', InputOption::VALUE_NONE, 'Allow remote hosts to connect to local forwarded ports');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();

        $container = $this->selectRemoteContainer($input, false);
        $appName = $container->getName();
        $sshUrl = $container->getSshUrl();
        $host = $this->selectHost($input, false, $container);

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $relationships = $relationshipsService->getRelationships($host);
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        $service = $relationshipsService->chooseService($host, $input, $output);
        if (!$service) {
            return 1;
        }

        if ($environment->is_main) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $confirmText = sprintf(
                'Are you sure you want to open an SSH tunnel to'
                . ' the relationship <comment>%s</comment> on the'
                . ' environment <comment>%s</comment>?',
                $service['_relationship_name'],
                $this->api()->getEnvironmentLabel($environment, false)
            );
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $sshOptions = [];
        if ($input->getOption('gateway-ports')) {
            $sshOptions['GatewayPorts'] = 'yes';
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshArgs = $ssh->getSshArgs($sshOptions);

        $remoteHost = $service['host'];
        $remotePort = $service['port'];

        if ($localPort = $input->getOption('port')) {
            if (!PortUtil::validatePort($localPort)) {
                $this->stdErr->writeln(sprintf('Invalid port: <error>%s</error>', $localPort));

                return 1;
            }
            if (PortUtil::isPortInUse($localPort)) {
                $this->stdErr->writeln(sprintf('Port already in use: <error>%s</error>', $localPort));

                return 1;
            }
        } else {
            $localPort = $this->getPort();
        }

        $tunnel = [
            'projectId' => $project->id,
            'environmentId' => $environment->id,
            'appName' => $appName,
            'relationship' => $service['_relationship_name'],
            'serviceKey' => $service['_relationship_key'],
            'remotePort' => $remotePort,
            'remoteHost' => $remoteHost,
            'localPort' => $localPort,
            'service' => $service,
            'pid' => null,
        ];

        $relationshipString = $this->formatTunnelRelationship($tunnel);

        if ($openTunnelInfo = $this->isTunnelOpen($tunnel)) {
            $this->stdErr->writeln(sprintf(
                'A tunnel is already opened to the relationship <info>%s</info>, at: <info>%s</info>',
                $relationshipString,
                $this->getTunnelUrl($openTunnelInfo, $service)
            ));

            return 1;
        }

        $pidFile = $this->getPidFile($tunnel);

        $processManager = new ProcessManager();
        $process = $this->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $sshArgs);
        $pid = $processManager->startProcess($process, $pidFile, $output);

        // Wait a very small time to capture any immediate errors.
        usleep(100000);
        if (!$process->isRunning() && !$process->isSuccessful()) {
            $this->stdErr->writeln(trim($process->getErrorOutput()));
            $this->stdErr->writeln(sprintf(
                'Failed to open tunnel for relationship: <error>%s</error>',
                $relationshipString
            ));
            unlink($pidFile);

            return 1;
        }

        $tunnel['pid'] = $pid;
        $this->tunnelInfo[] = $tunnel;
        $this->saveTunnelInfo();

        if ($output->isVerbose()) {
            // Just an extra line for separation from the process manager's log.
            $this->stdErr->writeln('');
        }

        $this->stdErr->writeln(sprintf(
            'SSH tunnel opened to <info>%s</info> at: <info>%s</info>',
            $relationshipString,
            $this->getTunnelUrl($tunnel, $service)
        ));

        $this->stdErr->writeln('');

        $this->stdErr->writeln('Quitting this command (with Ctrl+C or equivalent) will close the tunnel.');

        $this->stdErr->writeln('');

        $processManager->monitor($this->stdErr);

        return $process->isSuccessful() ? 0 : 1;
    }
}
