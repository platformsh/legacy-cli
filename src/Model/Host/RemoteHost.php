<?php

namespace Platformsh\Cli\Model\Host;

use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Util\OsUtil;

class RemoteHost implements HostInterface
{
    private $sshUrl;
    private $sshService;
    private $shell;
    private $extraSshArgs = [];
    private $sshDiagnostics;

    public function __construct($sshUrl, Ssh $sshService, Shell $shell, SshDiagnostics $sshDiagnostics)
    {
        $this->sshUrl = $sshUrl;
        $this->sshService = $sshService;
        $this->shell = $shell;
        $this->sshDiagnostics = $sshDiagnostics;
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel()
    {
        return $this->sshUrl;
    }

    /**
     * @param array $args
     */
    public function setExtraSshArgs(array $args)
    {
        $this->extraSshArgs = $args;
    }

    /**
     * {@inheritDoc}
     */
    public function runCommand($command, $mustRun = true, $quiet = true, $input = null)
    {
        try {
            $result = $this->shell->execute($this->wrapCommandLine($command), null, $mustRun, $quiet, [], 3600, $input);
            if ($result === false) {
                $this->sshDiagnostics->diagnoseFailure($this->sshUrl);
            }
            return $result;
        } catch (ProcessFailedException $e) {
            $this->sshDiagnostics->diagnoseFailure($this->sshUrl, $e->getProcess(), false);
            throw new ProcessFailedException($e->getProcess(), false);
        }
    }

    /**
     * Converts a command like "pwd" to "ssh username@host 'pwd'".
     *
     * @param string $commandLine
     *
     * @return string
     */
    private function wrapCommandLine($commandLine)
    {
        return $this->sshService->getSshCommand()
            . ($this->extraSshArgs ? ' ' . implode(' ', array_map([OsUtil::class, 'escapeShellArg'], $this->extraSshArgs)) : '')
            . ' ' . escapeshellarg($this->sshUrl)
            . ' ' . escapeshellarg($commandLine);
    }

    /**
     * {@inheritDoc}
     */
    public function runCommandDirect($commandLine, $append = '')
    {
        $exitCode = $this->shell->executeSimple($this->wrapCommandLine($commandLine) . $append);
        if ($exitCode !== 0) {
            $this->sshDiagnostics->diagnoseFailure($this->sshUrl, null, false);
        }
        return $exitCode;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheKey()
    {
        return $this->sshUrl;
    }
}
