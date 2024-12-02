<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Console\ProcessManager;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tunnel:open', description: "Open SSH tunnels to an app's relationships")]
class TunnelOpenCommand extends TunnelCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector, private readonly Ssh $ssh)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('gateway-ports', 'g', InputOption::VALUE_NONE, 'Allow remote hosts to connect to local forwarded ports');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
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

    public function isHidden(): bool
    {
        return parent::isHidden() || OsUtil::isWindows();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (OsUtil::isWindows()) {
            $this->stdErr->writeln('This command does not work on Windows, as the required PHP extensions are unavailable.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You can use the <info>tunnel:single</info> command instead.');
            return 1;
        }
        if ($missingExtensions = $this->missingExtensions()) {
            $this->stdErr->writeln(sprintf('The following required PHP extension(s) are missing or not enabled: <error>%s</error>', implode('</error>, <error>', $missingExtensions)));
            $this->stdErr->writeln('');
            $this->stdErr->writeln('The alternative <info>tunnel:single</info> command does not require these extensions.');

            return 1;
        }

        $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();

        $container = $this->selectRemoteContainer($input, false);
        $appName = $container->getName();
        $sshUrl = $container->getSshUrl();
        $host = $this->selectHost($input, false, $container);

        $relationshipsService = $this->relationships;
        $relationships = $relationshipsService->getRelationships($host);
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        if ($environment->is_main) {
            $questionHelper = $this->questionHelper;
            $confirmText = \sprintf('Are you sure you want to open SSH tunnel(s) to the environment %s?', $this->api->getEnvironmentLabel($environment, 'comment'));
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $logFile = $this->config->getWritableUserDir() . '/tunnels.log';
        if (!$log = $this->openLog($logFile)) {
            $this->stdErr->writeln(sprintf('Failed to open log file for writing: %s', $logFile));
            return 1;
        }

        $sshOptions = [];
        if ($input->getOption('gateway-ports')) {
            $sshOptions[] = 'GatewayPorts yes';
        }

        $ssh = $this->ssh;
        $sshArgs = $ssh->getSshArgs($sshUrl, $sshOptions);

        $log->setVerbosity($output->getVerbosity());

        // It seems PHP or the Phar extension cannot load new classes after
        // forking in some circumstances. Preload classes that are needed here
        // to avoid class not found errors later.
        // TODO find out exactly why this is required
        $this->io->debug('Preloading class before forking: ' . ConsoleTerminateEvent::class);

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
            $executable = $this->config->get('application.executable');
            $variable = $this->config->get('service.env_prefix') . 'RELATIONSHIPS';
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

    /**
     * Checks if any required PHP extensions are unavailable.
     *
     * @return string[]
     */
    private function missingExtensions(): array
    {
        $missing = [];
        foreach (['pcntl', 'posix'] as $ext) {
            if (!\extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        return $missing;
    }
}
