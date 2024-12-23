<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'environment:xdebug', description: 'Open a tunnel to Xdebug on the environment', aliases: ['xdebug'])]
class EnvironmentXdebugCommand extends CommandBase
{
    public const SOCKET_PATH = '/run/xdebug-tunnel.sock';
    public function __construct(private readonly ApplicationFinder $applicationFinder, private readonly Config $config, private readonly Io $io, private readonly Selector $selector, private readonly Ssh $ssh)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('port', null, InputArgument::OPTIONAL, 'The local port', 9000);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Connect to Xdebug on the environment, listening locally on port 9000.');
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        // Hide this command in the list if the project is not PHP.
        $projectRoot = $this->selector->getProjectRoot();
        if ($projectRoot) {
            try {
                return !$this->isPhp($projectRoot);
            } catch (\Exception) {
                // Ignore errors when loading or parsing configuration.
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a project contains a PHP app.
     */
    private function isPhp(string $directory): bool
    {
        static $isPhp;
        if (!isset($isPhp)) {
            $isPhp = false;
            $finder = $this->applicationFinder;
            foreach ($finder->findApplications($directory) as $app) {
                $type = $app->getType();
                if ($type === 'php' || str_starts_with((string) $type, 'php:')) {
                    $isPhp = true;
                    break;
                }
            }
        }

        return $isPhp;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));

        $container = $selection->getRemoteContainer();
        $sshUrl = $container->getSshUrl($input->getOption('instance'));

        $config = $container->getConfig()->getNormalized();
        $ideKey = $config['runtime']['xdebug']['idekey'] ?? '';

        if (!$ideKey) {
            $this->stdErr->writeln('<error>No IDE key found.</error>');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('To use Xdebug your project must have an <comment>idekey</comment> value set.');
            $this->stdErr->writeln('');
            if ($this->config->has('service.app_config_file')) {
                $this->stdErr->writeln(sprintf('Set this in the <comment>%s</comment> file as in this example:', $this->config->getStr('service.app_config_file')));
            } else {
                $this->stdErr->writeln('Set this in the application configuration file as in this example:');
            }
            $this->stdErr->writeln(
                "\n<comment># ...\n"
                . "runtime:\n"
                . "    xdebug:\n"
                . "        idekey: <options=underscore>secret_key</>",
            );

            return 1;
        }

        // The socket is removed to prevent 'file already exists' errors.
        $commandCleanup = $this->ssh->getSshCommand($sshUrl, [], 'rm -rf ' . self::SOCKET_PATH);
        $this->io->debug("Cleanup command: " . $commandCleanup);
        $process = Process::fromShellCommandline($commandCleanup, null, $this->ssh->getEnv());
        $process->run();

        $this->stdErr->writeln("Opening a local tunnel for Xdebug.");

        // Set up the tunnel
        $port = $input->getOption('port');

        $sshOptions = ['ExitOnForwardFailure yes', 'SessionType none', 'RequestTTY no'];

        $listenAddress = '127.0.0.1:' . $port;
        $commandTunnel = $this->ssh->getSshCommand($sshUrl, $sshOptions) . ' -R ' . escapeshellarg(self::SOCKET_PATH . ':' . $listenAddress);
        $this->io->debug("Tunnel command: " . $commandTunnel);
        $process = Process::fromShellCommandline($commandTunnel, null, $this->ssh->getEnv());
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
            . " or append '<info>XDEBUG_SESSION_START=$ideKey</info>' in the URL query string when visiting your project.",
        );
        $this->stdErr->writeln('');
        $this->stdErr->writeln('To close the tunnel, quit this command by pressing <info>Ctrl+C</info>.');
        $this->stdErr->writeln('To change the local port, re-run this command with the <info>--port</info> option.');

        return $process->wait();
    }
}
