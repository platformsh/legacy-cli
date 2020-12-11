<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentScpCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:scp')
            ->setAliases(['scp'])
            ->addArgument('files', InputArgument::IS_ARRAY, 'Files to copy. Use the remote: prefix to define remote locations.')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Recursively copy entire directories')
            ->setDescription('Copy files to and from current environment using scp');
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addRemoteContainerOptions();
        Ssh::configureInput($this->getDefinition());
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

        $this->validateInput($input);

        $container = $this->selectRemoteContainer($input);
        $sshUrl = $container->getSshUrl();

        /** @var Ssh $ssh */
        $ssh = $this->getService('ssh');
        $command = 'scp';

        if ($sshArgs = $ssh->getSshArgs()) {
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
        foreach ($files as $key => $file) {
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

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $start = \time();

        $exitCode = $shell->executeSimple($command);
        if ($exitCode !== 0) {
            /** @var \Platformsh\Cli\Service\SshDiagnostics $diagnostics */
            $diagnostics = $this->getService('ssh_diagnostics');
            $diagnostics->diagnoseFailureWithTest($sshUrl, $start, $exitCode);
        }

        return $exitCode;
    }
}
