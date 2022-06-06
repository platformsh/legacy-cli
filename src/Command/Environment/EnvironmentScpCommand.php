<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentScpCommand extends CommandBase
{
    protected static $defaultName = 'environment:scp|scp';
    protected static $defaultDescription = 'Copy files to and from the current environment using scp';

    private $diagnostics;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Selector $selector,
        Shell $shell,
        Ssh $ssh,
        SshDiagnostics $sshDiagnostics
    ) {
        $this->diagnostics = $sshDiagnostics;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('files', InputArgument::IS_ARRAY, 'Files to copy. Use the remote: prefix to define remote locations.')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recursively copy entire directories');
        $this->selector->addAllOptions($this->getDefinition());
        $this->ssh->configureInput($this->getDefinition());
        $this->addExample('Copy local files a.txt and b.txt to remote mount var/files', "a.txt b.txt remote:var/files");
        $this->addExample('Copy remote files c.txt to current directory', "remote:c.txt .");
        $this->addExample('Copy subdirectory dump/ to remote mount var/files', "-r dump remote:var/logs");
        $this->addExample('Copy files inside subdirectory dump/ to remote mount var/files', "-r dump/* remote:var/logs");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $files = $input->getArgument('files');
        if (!$files) {
            throw new InvalidArgumentException('No files specified');
        }

        $selection = $this->selector->getSelection($input);

        $container = $selection->getRemoteContainer();
        $sshUrl = $container->getSshUrl();

        $command = 'scp';

        if ($sshArgs = $this->ssh->getSshArgs()) {
            $command .= ' ' . implode(' ', array_map([OsUtil::class, 'escapeShellArg'], $sshArgs));
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
            if (strpos($file, 'remote:') === 0) {
                $command .= ' ' . escapeshellarg($sshUrl . ':' . substr($file, 7));
                $remoteUsed = true;
            } else {
                $command .= ' ' . escapeshellarg($file);
            }
        }

        if (!$remoteUsed) {
            throw new InvalidArgumentException('At least one argument needs to contain the "remote:" prefix');
        }

        $start = \time();

        $exitCode = $this->shell->executeSimple($command);
        if ($exitCode !== 0) {
            $this->diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
