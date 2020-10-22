<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Console\ProcessManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelOpenCommand extends TunnelCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('tunnel:open')
            ->setDescription("Open SSH tunnels to an app's relationships");
        $this->addOption('gateway-ports', 'g', InputOption::VALUE_NONE, 'Allow remote hosts to connect to local forwarded ports');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        Ssh::configureInput($this->getDefinition());
        $this->setHelp(<<<EOF
This command opens SSH tunnels to all of the relationships of an application.

Connections can then be made to the application's services as if they were
local, for example a local MySQL client can be used, or the Solr web
administration endpoint can be accessed through a local browser.

This command requires the posix and pcntl PHP extensions (as multiple
background CLI processes are created to keep the SSH tunnels open). The
<info>tunnel:single</info> command can be used on systems without these
extensions.
EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $requiredExtensions = ['pcntl', 'posix'];
        $missingExtensions = [];
        foreach ($requiredExtensions as $requiredExtension) {
            if (!extension_loaded($requiredExtension)) {
                $missingExtensions[] = $requiredExtension;
                $this->stdErr->writeln(sprintf('The <error>%s</error> PHP extension is required.', $requiredExtension));
            }
        }
        if (!empty($missingExtensions)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('The alternative <info>tunnel:single</info> command does not require these extensions.');

            return 1;
        }

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

        if ($environment->id === 'master') {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $confirmText = 'Are you sure you want to open SSH tunnel(s) to the'
                . ' <comment>master</comment> (production) environment?';
            if (!$questionHelper->confirm($confirmText, false)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $logFile = $this->config()->getWritableUserDir() . '/tunnels.log';
        if (!$log = $this->openLog($logFile)) {
            $this->stdErr->writeln(sprintf('Failed to open log file for writing: %s', $logFile));
            return 1;
        }

        $sshOptions = [];
        if ($input->getOption('gateway-ports')) {
            $sshOptions['GatewayPorts'] = 'yes';
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshArgs = $ssh->getSshArgs($sshOptions);

        $log->setVerbosity($output->getVerbosity());

        $processManager = new ProcessManager();
        $processManager->fork();

        $error = false;
        $processIds = [];
        foreach ($relationships as $relationship => $services) {
            foreach ($services as $serviceKey => $service) {
                $remoteHost = $service['host'];
                $remotePort = $service['port'];

                $localPort = $this->getPort();
                $tunnel = [
                    'projectId' => $project->id,
                    'environmentId' => $environment->id,
                    'appName' => $appName,
                    'relationship' => $relationship,
                    'serviceKey' => $serviceKey,
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
                    continue;
                }

                $process = $this->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $sshArgs);

                $pidFile = $this->getPidFile($tunnel);

                try {
                    $pid = $processManager->startProcess($process, $pidFile, $log);
                } catch (\Exception $e) {
                    $this->stdErr->writeln(sprintf(
                        'Failed to open tunnel for relationship <error>%s</error>: %s',
                        $relationshipString,
                        $e->getMessage()
                    ));
                    $error = true;
                    continue;
                }

                // Wait a very small time to capture any immediate errors.
                usleep(100000);
                if (!$process->isRunning() && !$process->isSuccessful()) {
                    $this->stdErr->writeln(trim($process->getErrorOutput()));
                    $this->stdErr->writeln(sprintf(
                        'Failed to open tunnel for relationship: <error>%s</error>',
                        $relationshipString
                    ));
                    unlink($pidFile);
                    $error = true;
                    continue;
                }

                // Save information about the tunnel for use in other commands.
                $tunnel['pid'] = $pid;
                $this->tunnelInfo[] = $tunnel;
                $this->saveTunnelInfo();

                $this->stdErr->writeln(sprintf(
                    'SSH tunnel opened to <info>%s</info> at: <info>%s</info>',
                    $relationshipString,
                    $this->getTunnelUrl($tunnel, $service)
                ));

                $processIds[] = $pid;
            }
        }

        if (count($processIds)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln("Logs are written to: $logFile");
        }

        if (!$error) {
            $executable = $this->config()->get('application.executable');
            $variable = $this->config()->get('service.env_prefix') . 'RELATIONSHIPS';
            $this->stdErr->writeln('');
            $this->stdErr->writeln("List tunnels with: <info>$executable tunnels</info>");
            $this->stdErr->writeln("View tunnel details with: <info>$executable tunnel:info</info>");
            $this->stdErr->writeln("Close tunnels with: <info>$executable tunnel:close</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                "Save encoded tunnel details to the $variable variable using:"
                . "\n  <info>export $variable=\"$($executable tunnel:info --encode)\"</info>"
            );
        }

        $processManager->killParent($error);

        $processManager->monitor($log);

        return 0;
    }
}
