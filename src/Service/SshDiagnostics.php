<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SshDiagnostics
{
    // This would be a private const from PHP 7.1
    const _SSH_ERROR_EXIT_CODE = 255;

    private $ssh;
    private $sshKey;
    private $certifier;
    private $stdErr;
    private $api;
    private $config;

    private $connectionTestResult;

    public function __construct(Ssh $ssh, OutputInterface $output, Certifier $certifier, SshKey $sshKey, Api $api, Config $config)
    {
        $this->ssh = $ssh;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
        $this->api = $api;
        $this->config = $config;
    }

    /**
     * Checks whether the SSH connection fails due to MFA requirements.
     *
     * @param string $uri
     * @param Process|null $failedProcess
     *
     * @return bool
     */
    private function connectionFailedDueToMFA($uri, $failedProcess = null)
    {
        if (($cert = $this->certifier->getExistingCertificate()) && $cert->hasMfa()) {
            // MFA is verified.
            return false;
        }
        $failedProcess = $failedProcess ?: $this->testConnection($uri);

        return stripos($failedProcess->getErrorOutput(), 'reason: access requires MFA') !== false;
    }

    /**
     * Tests the SSH connection (and caches the result).
     *
     * @param string $uri
     * @param bool   $reset
     *
     * @return Process
     *   A process (already run) that tested the SSH connection.
     */
    private function testConnection($uri, $reset = false)
    {
        if (!$reset && isset($this->connectionTestResult)) {
            return $this->connectionTestResult;
        }
        $process = new Process($this->ssh->getSshCommand([], $uri, 'exit'));
        $process->run();
        return $this->connectionTestResult = $process;
    }

    /**
     * Diagnoses and reports reasons for an SSH command failure.
     *
     * @param string $uri
     * @param Process|null $failedProcess
     *   The failed SSH process. Another SSH command will run automatically if a process is not available.
     * @param bool $blankLine
     *   Whether to output a blank line first if anything will be printed.
     */
    public function diagnoseFailure($uri, Process $failedProcess = null, $blankLine = true)
    {
        $failedProcess = $failedProcess ?: $this->testConnection($uri);
        if ($failedProcess->getExitCode() !== self::_SSH_ERROR_EXIT_CODE) {
            return;
        }

        $executable = $this->config->get('application.executable');

        if ($this->connectionFailedDueToMFA($uri, $failedProcess)) {
            if ($blankLine) {
                $this->stdErr->writeln('');
            }

            if (!$this->certifier->getExistingCertificate() && !$this->certifier->isAutoLoadEnabled()) {
                $this->stdErr->writeln('The SSH connection failed. An SSH certificate is required.');
                $this->stdErr->writeln(sprintf('Generate one using: <comment>%s ssh-cert:load</comment>', $executable));
                return;
            }

            $this->stdErr->writeln('The SSH connection failed because access requires MFA (multi-factor authentication).');

            if ($this->api->getUser()->mfa_enabled) {
                $this->stdErr->writeln('MFA is currently enabled on your account, but reverification is required.');
                $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
            } else {
                $this->stdErr->writeln('MFA is not yet enabled on your account.');
                if ($this->config->has('api.mfa_setup_url')) {
                    $this->stdErr->writeln(\sprintf('Set up MFA at: <comment>%s</comment>', $this->config->get('api.mfa_setup_url')));
                }
                $this->stdErr->writeln(\sprintf('Then log in again with: <comment>%s login -f</comment>', $executable));
            }
            return;
        }

        if (!$this->sshKey->hasLocalKey()) {
            if ($blankLine) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln(sprintf(
                'You probably need to add an SSH key, with: <comment>%s ssh-key:add</comment>',
                $executable
            ));
            return;
        }
    }
}
