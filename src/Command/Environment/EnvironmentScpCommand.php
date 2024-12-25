<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:scp', description: 'Copy files to and from an environment using scp', aliases: ['scp'])]
class EnvironmentScpCommand extends CommandBase
{
    public function __construct(private readonly Selector $selector, private readonly Shell $shell, private readonly Ssh $ssh, private readonly SshDiagnostics $sshDiagnostics)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY, 'Files to copy. Use the remote: prefix to define remote locations.')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recursively copy entire directories');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Copy local files a.txt and b.txt to remote mount var/files', "a.txt b.txt remote:var/files");
        $this->addExample('Copy remote files c.txt to current directory', "remote:c.txt .");
        $this->addExample('Copy subdirectory dump/ to remote mount var/files', "-r dump remote:var/logs");
        $this->addExample('Copy files inside subdirectory dump/ to remote mount var/files', "-r dump/* remote:var/logs");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $input->getArgument('files');
        if (!$files) {
            throw new InvalidArgumentException('No files specified');
        }

        $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
        $container = $selection->getRemoteContainer();

        $sshUrl = $container->getSshUrl($input->getOption('instance'));
        $command = 'scp';

        if ($sshArgs = $this->ssh->getSshArgs($sshUrl)) {
            $command .= ' ' . implode(' ', array_map(OsUtil::escapePosixShellArg(...), $sshArgs));
        }

        if ($input->getOption('recursive')) {
            $command .= ' -r';
        }

        if ($output->isVeryVerbose()) {
            $command .= ' -v';
        } elseif ($output->isQuiet()) {
            $command .= ' -q';
        }

        $remoteUsed = false;
        foreach ($files as $file) {
            if (str_starts_with((string) $file, 'remote:')) {
                $command .= ' ' . escapeshellarg($sshUrl . ':' . substr((string) $file, 7));
                $remoteUsed = true;
            } else {
                $command .= ' ' . escapeshellarg((string) $file);
            }
        }

        if (!$remoteUsed) {
            throw new InvalidArgumentException('At least one argument needs to contain the "remote:" prefix');
        }

        $start = \time();

        $exitCode = $this->shell->executeSimple($command);
        if ($exitCode !== 0) {
            $diagnostics = $this->sshDiagnostics;
            $diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
