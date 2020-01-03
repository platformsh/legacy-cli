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
        $environment = $this->getSelectedEnvironment();

        $port = $input->getOption('port');
        $socketPath = "/run/xdebug-tunnel.sock";

        $container = $this->selectRemoteContainer($input);
        $sshUrl = $container->getSshUrl();

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshOptions = [];

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $commandCleanup = $ssh->getSshCommand($sshOptions);
        $commandCleanup .= ' ' . escapeshellarg($sshUrl) . ' rm ' . escapeshellarg($socketPath);
        $process = new Process($commandCleanup, null, null, null, null);
        $process->run();

        $output->writeln("Starting the tunnel for Xdebug.");
        $sshOptions['ExitOnForwardFailure'] = 'yes';
        $commandTunnel = $ssh->getSshCommand($sshOptions) . ' -TNR ' . escapeshellarg($socketPath . ':127.0.0.1:' . $port);
        $commandTunnel .= ' ' . escapeshellarg($sshUrl);
        $process = new Process($commandTunnel, null, null, null, null);
        $process->start();

        usleep(100000);

        if (!$process->isRunning() && !$process->isSuccessful()) {
            $this->stdErr->writeln(trim($process->getErrorOutput()));
            $this->stdErr->writeln('Failed to create the tunnel.');
            return $process;
        }

        $output->writeln(
            sprintf(
                "The Xdebug tunnel is set up. To break it, close this command by pressing CTRL+C.\n " .
                "\n" .
                "\To debug, you must either set a cookie like '<info>XDEBUG_SESSION=</info>' or append '<info>XDEBUG_SESSION_START=key</info>' as a query string when visiting your project."
            )
        );

        $process->wait();

        return $process;
    }
}
