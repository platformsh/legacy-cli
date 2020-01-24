<?php

namespace Platformsh\Cli\Model\Host;

use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;

class RemoteHost implements HostInterface
{
    private $sshUrl;
    private $sshService;
    private $shell;
    private $extraSshArgs = [];

    public function __construct($sshUrl, Ssh $sshService, Shell $shell)
    {
        $this->sshUrl = $sshUrl;
        $this->sshService = $sshService;
        $this->shell = $shell;
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
    public function runCommand($command, $mustRun = true, $quiet = true)
    {
        return $this->shell->execute($this->wrapCommandLine($command), null, $mustRun, $quiet);
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
        return $this->shell->executeSimple($this->wrapCommandLine($commandLine) . $append);
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheKey()
    {
        return $this->sshUrl;
    }
}
