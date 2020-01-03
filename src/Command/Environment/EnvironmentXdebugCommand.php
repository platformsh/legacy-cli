<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('port', null, InputArgument::OPTIONAL, 'The local port for Xdebug to connect to.', 9000)
            ->setDescription('Reverse tunnel Xdebug to the current host');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addRemoteContainerOptions();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Connect the environment to Xdebug listening locally on port 9000.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $this->getSelectedEnvironment();

        $container = $this->selectRemoteContainer($input);
        $sshUrl = $container->getSshUrl();

        $config = $container->getConfig()->getNormalized();
        $key = isset($config['runtime']['xdebug']['key']) ? $config['runtime']['xdebug']['key'] : '';

        if (!$key) {
            $output->getErrorOutput()->writeln(
                "<error>A debugging key has not been found</error>\n" .
                "\n" .
                "To use Xdebug your project must have a <info>debugging key</info> informed.\n" .
                "Such key is informed in the <info>.platform.app.yaml</info> file as in this example:\n" .
                "\n" .
                "<info>...\n" .
                "runtime:\n" .
                "    xdebug:\n" .
                "        key: <options=underscore>secret_key</>"
            );

            return 1;
        }


        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');

        // The socket is removed to prevent 'file already exists' errors
        $commandCleanup = $ssh->getSshCommand();
        $commandCleanup .= ' ' . escapeshellarg($sshUrl) . ' rm -f ' . escapeshellarg(self::SOCKET_PATH);
        $this->debug("Cleanup command: " . $commandCleanup);
        $process = new Process($commandCleanup);
        $process->run();

        $output->writeln("Starting the tunnel for Xdebug.");

        // Set up the tunnel
        $port = $input->getOption('port');

        $sshOptions = [];
        $sshOptions['ExitOnForwardFailure'] = 'yes';

        $commandTunnel = $ssh->getSshCommand($sshOptions) . ' -TNR ' . escapeshellarg(self::SOCKET_PATH . ':127.0.0.1:' . $port);
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

        $output->writeln(
            sprintf(
                "\nThe Xdebug tunnel is set up. To break it, close this command by pressing <info>CTRL+C</info>.\n " .
                "\n" .
                "To debug, you must either set a cookie like '<info>XDEBUG_SESSION=%s</info>' or append '<info>XDEBUG_SESSION_START=%s</info>' as a query string when visiting your project.",
                $key, $key
            )
        );

        return $process->wait();
    }
}
