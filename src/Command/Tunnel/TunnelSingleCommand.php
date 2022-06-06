<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ProcessManager;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\TunnelService;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelSingleCommand extends CommandBase
{
    protected static $defaultName = 'tunnel:single';
    protected static $defaultDescription = 'Open a single SSH tunnel to an app relationship';

    private $api;
    private $questionHelper;
    private $relationshipsService;
    private $selector;
    private $ssh;
    private $tunnelService;

    public function __construct(
        Api $api,
        QuestionHelper $questionHelper,
        Relationships $relationshipsService,
        Selector $selector,
        Ssh $ssh,
        TunnelService $tunnelService
    ) {
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->relationshipsService = $relationshipsService;
        $this->selector = $selector;
        $this->ssh = $ssh;
        $this->tunnelService = $tunnelService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'The local port')
            ->addOption('gateway-ports', 'g', InputOption::VALUE_NONE, 'Allow remote hosts to connect to local forwarded ports');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationshipsService->configureInput($definition);
        $this->ssh->configureInput($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();
        $appName = $selection->getAppName();

        $sshUrl = $environment->getSshUrl($appName);

        $relationships = $this->relationshipsService->getRelationships($selection->getHost());
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        $service = $this->relationshipsService->chooseService($selection->getHost(), $input, $output);
        if (!$service) {
            return 1;
        }

        if ($environment->is_main) {
            $confirmText = sprintf(
                'Are you sure you want to open an SSH tunnel to'
                . ' the relationship <comment>%s</comment> on the'
                . ' environment <comment>%s</comment>?',
                $service['_relationship_name'],
                $this->api->getEnvironmentLabel($environment, false)
            );
            if (!$this->questionHelper->confirm($confirmText, false)) {
                return 1;
            }
            $this->stdErr->writeln('');
        }

        $sshOptions = [];
        if ($input->getOption('gateway-ports')) {
            $sshOptions['GatewayPorts'] = 'yes';
        }

        $sshArgs = $this->ssh->getSshArgs($sshOptions);

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
            $localPort = $this->tunnelService->getPort();
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

        $relationshipString = $this->tunnelService->formatTunnelRelationship($tunnel);

        if ($openTunnelInfo = $this->tunnelService->isTunnelOpen($tunnel)) {
            $this->stdErr->writeln(sprintf(
                'A tunnel is already opened to the relationship <info>%s</info>, at: <info>%s</info>',
                $relationshipString,
                $this->tunnelService->getTunnelUrl($openTunnelInfo, $service)
            ));

            return 1;
        }

        $pidFile = $this->tunnelService->getPidFile($tunnel);

        $processManager = new ProcessManager();
        $process = $this->tunnelService->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $sshArgs);
        $pid = $processManager->startProcess($process, $pidFile, $this->stdErr);

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
        $this->tunnelService->addTunnelInfo($tunnel);

        if ($output->isVerbose()) {
            // Just an extra line for separation from the process manager's log.
            $this->stdErr->writeln('');
        }

        $this->stdErr->writeln(sprintf(
            'SSH tunnel opened to <info>%s</info> at: <info>%s</info>',
            $relationshipString,
            $this->tunnelService->getTunnelUrl($tunnel, $service)
        ));

        $this->stdErr->writeln('');

        $this->stdErr->writeln('Quitting this command (with Ctrl+C or equivalent) will close the tunnel.');

        $this->stdErr->writeln('');

        $processManager->monitor($this->stdErr);

        return $process->isSuccessful() ? 0 : 1;
    }
}
