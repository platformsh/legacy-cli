<?php

namespace Platformsh\Cli\Model\Host;

use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Client\Model\Environment;

class RemoteHost implements HostInterface
{
    private array $extraSshOptions = [];

    public function __construct(private readonly string $sshUrl, private readonly Environment $environment, private readonly Ssh $sshService, private readonly Shell $shell, private readonly SshDiagnostics $sshDiagnostics)
    {
    }

    public function getLabel(): string
    {
        return $this->sshUrl;
    }

    /**
     * @param string[] $options
     */
    public function setExtraSshOptions(array $options): void
    {
        $this->extraSshOptions = $options;
    }

    /**
     * {@inheritDoc}
     */
    public function runCommand(string $command, bool $mustRun = true, bool $quiet = true, mixed $input = null): bool|string
    {
        try {
            return $this->shell->execute($this->wrapCommandLine($command), null, $mustRun, $quiet, $this->sshService->getEnv(), 3600, $input);
        } catch (ProcessFailedException $e) {
            $this->sshDiagnostics->diagnoseFailure($this->sshUrl, $e->getProcess(), !$quiet);
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
    private function wrapCommandLine(string $commandLine): string
    {
        return $this->sshService->getSshCommand($this->sshUrl, $this->extraSshOptions, $commandLine);
    }

    public function runCommandDirect($commandLine, $append = ''): int
    {
        $start = \time();
        $exitCode = $this->shell->executeSimple($this->wrapCommandLine($commandLine) . $append, null, $this->sshService->getEnv());
        $this->sshDiagnostics->diagnoseFailureWithTest($this->sshUrl, $start, $exitCode);
        return $exitCode;
    }

    public function getCacheKey(): string
    {
        return $this->sshUrl . '--' . $this->environment->head_commit;
    }

    public function lastChanged(): string
    {
        $deployment_state = $this->environment->getProperty('deployment_state', false, false);
        return $deployment_state['last_deployment_at'] ?? '';
    }
}
