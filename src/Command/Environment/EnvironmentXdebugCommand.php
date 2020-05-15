<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class EnvironmentXdebugCommand extends CommandBase
{
    const SOCKET_PATH = '/run/xdebug-tunnel.sock';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:xdebug')
            ->setAliases(['xdebug'])
            ->addOption('port', null, InputArgument::OPTIONAL, 'The local port', 9000)
            ->setDescription('Open a tunnel to Xdebug on the environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addRemoteContainerOptions();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Connect to Xdebug on the environment, listening locally on port 9000.');
    }

    public function isHidden()
    {
        // Hide this command in the list if the project is not PHP.
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            try {
                return !$this->isPhp($projectRoot);
            } catch (\Exception $e) {
                // Ignore errors when loading or parsing configuration.
                return true;
            }
        }

        return parent::isHidden();
    }

    /**
     * Checks if a project contains a PHP app.
     *
     * @param string $directory
     *
     * @return bool
     */
    private function isPhp($directory) {
        static $isPhp;
        if (!isset($isPhp)) {
            $isPhp = false;
            foreach (LocalApplication::getApplications($directory, $this->config()) as $app) {
                $type = $app->getType();
                if ($type === 'php' || strpos($type, 'php:') === 0) {
                    $isPhp = true;
                    break;
                }
            }
        }

        return $isPhp;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $this->getSelectedEnvironment();

        $container = $this->selectRemoteContainer($input);
        $sshUrl = $container->getSshUrl();

        $config = $container->getConfig()->getNormalized();
        $ideKey = isset($config['runtime']['xdebug']['idekey']) ? $config['runtime']['xdebug']['idekey'] : '';

        if (!$ideKey) {
            $this->stdErr->writeln('<error>No IDE key found.</error>');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('To use Xdebug your project must have an <comment>idekey</comment> value set.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('Set this in the <comment>%s</comment> file as in this example:', $this->config()->get('service.app_config_file')));
            $this->stdErr->writeln(
                "\n<comment># ...\n"
                . "runtime:\n"
                . "    xdebug:\n"
                . "        idekey: <options=underscore>secret_key</>"
            );

            return 1;
        }


        /** @var Ssh $ssh */
        $ssh = $this->getService('ssh');

        // The socket is removed to prevent 'file already exists' errors.
        $commandCleanup = $ssh->getSshCommand();
        $commandCleanup .= ' ' . escapeshellarg($sshUrl) . ' rm -f ' . escapeshellarg(self::SOCKET_PATH);
        $this->debug("Cleanup command: " . $commandCleanup);
        $process = new Process($commandCleanup);
        $process->run();

        $this->stdErr->writeln("Opening a local tunnel for Xdebug.");

        // Set up the tunnel
        $port = $input->getOption('port');

        $sshOptions = [];
        $sshOptions['ExitOnForwardFailure'] = 'yes';

        $listenAddress = '127.0.0.1:' . $port;
        $commandTunnel = $ssh->getSshCommand($sshOptions) . ' -TNR ' . escapeshellarg(self::SOCKET_PATH . ':' . $listenAddress);
        $commandTunnel .= ' ' . escapeshellarg($sshUrl);
        $this->debug("Tunnel command: " . $commandTunnel);
        $process = new Process($commandTunnel);
        $process->setTimeout(null);
        $process->start();

        usleep(100000);

        if (!$process->isRunning() && !$process->isSuccessful()) {
            $this->stdErr->writeln(trim($process->getErrorOutput()));
            $this->stdErr->writeln('Failed to create the tunnel.');
            return $process->stop();
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf('Xdebug tunnel opened at: <info>%s</info>', $listenAddress));
        $this->stdErr->writeln('');
        $this->stdErr->writeln(
            "To start debugging, set a cookie like '<info>XDEBUG_SESSION=$ideKey</info>'"
            . " or append '<info>XDEBUG_SESSION_START=$ideKey</info>' in the URL query string when visiting your project."
        );
        $this->stdErr->writeln('');
        $this->stdErr->writeln('To close the tunnel, quit this command by pressing <info>Ctrl+C</info>.');
        $this->stdErr->writeln('To change the local port, re-run this command with the <info>--port</info> option.');

        return $process->wait();
    }
}
