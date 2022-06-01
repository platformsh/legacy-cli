<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SshDiagnostics
{
    const _SSH_ERROR_EXIT_CODE = 255;

    private $ssh;
    private $sshKey;
    private $certifier;
    private $stdErr;
    private $api;
    private $config;

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
     * Checks whether the SSH connection failed due to MFA requirements.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function connectionFailedDueToMFA(Process $failedProcess)
    {
        return stripos($failedProcess->getErrorOutput(), 'reason: access requires MFA') !== false;
    }

    /**
     * Checks if SSH authentication succeeded, even if the connection failed.
     *
     * This occurs if the SSH key or certificate was correct, but the requested
     * service does not exist or the user does not have access to it.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function authenticationSucceeded(Process $failedProcess)
    {
        $stdErr = $failedProcess->getErrorOutput();

        return stripos($stdErr, "reason: service doesn't exist") !== false
            || stripos($stdErr, 'reason: service not found') !== false
            || stripos($stdErr, 'you successfully authenticated, but') !== false;
    }

    /**
     * Checks if SSH host key verification failed.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function hostKeyVerificationFailed(Process $failedProcess)
    {
        $stdErr = $failedProcess->getErrorOutput();

        return stripos($stdErr, "Host key verification failed.") !== false;
    }

    /**
     * Checks if SSH key authentication failed.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function keyAuthenticationFailed(Process $failedProcess)
    {
        return stripos($failedProcess->getErrorOutput(), "Permission denied (publickey)") !== false;
    }

    /**
     * Tests the SSH connection.
     *
     * @param string $uri
     *
     * @return Process
     *   A process (already run) that tested the SSH connection.
     */
    private function testConnection($uri)
    {
        $this->stdErr->writeln('Making test connection to diagnose SSH errors', OutputInterface::VERBOSITY_DEBUG);
        $process = new Process($this->ssh->getSshCommand([], $uri, 'exit'));
        $process->run();
        $this->stdErr->writeln('Test connection complete', OutputInterface::VERBOSITY_DEBUG);
        return $process;
    }

    /**
     * Hackily finds a host from an SSH URI.
     *
     * @param string $uri
     *
     * @return string|false
     */
    private function getHost($uri)
    {
        // Parse the SSH URI to get the hostname.
        if (\strpos($uri, '@') !== false) {
            list(, $uri) = \explode('@', $uri, 2);
        }
        if (\strpos($uri, '://') !== false) {
            list(, $uri) = \explode('://', $uri, 2);
        }
        if (\strpos($uri, ':') !== false) {
            list($uri, ) = \explode(':', $uri, 2);
        }
        return \parse_url('ssh://' . $uri, PHP_URL_HOST);
    }

    /**
     * Checks if an SSH URI is for an internal (first-party) SSH server.
     *
     * @param string $uri
     *
     * @return bool
     *  True if the URI is for an internal server, false if it's external or it cannot be determined.
     */
    public function sshHostIsInternal($uri)
    {
        $host = $this->getHost($uri);
        if ($host === false) {
            return false;
        }
        // Check against the wildcard list.
        foreach ($this->config->getWithDefault('api.ssh_domain_wildcards', []) as $wildcard) {
            if (\strpos($host, \str_replace('*.', '', $wildcard)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Diagnoses and reports reasons for an SSH command failure.
     *
     * @param string $uri
     *   The SSH connection URI.
     * @param Process $failedProcess
     *   The failed SSH process.
     */
    public function diagnoseFailure($uri, Process $failedProcess)
    {
        if (!$this->sshHostIsInternal($uri) || $failedProcess->getExitCode() !== self::_SSH_ERROR_EXIT_CODE) {
            return;
        }

        $executable = $this->config->get('application.executable');

        $mfaVerified = ($cert = $this->certifier->getExistingCertificate()) && $cert->hasMfa();

        if (!$mfaVerified && $this->connectionFailedDueToMFA($failedProcess)) {
            $this->stdErr->writeln('');

            if (!$this->certifier->getExistingCertificate() && !$this->certifier->isAutoLoadEnabled()) {
                $this->stdErr->writeln('The SSH connection failed. An SSH certificate is required.');
                $this->stdErr->writeln(sprintf('Generate one using: <comment>%s ssh-cert:load</comment>', $executable));
                return;
            }

            $this->stdErr->writeln('The SSH connection failed because access requires MFA (multi-factor authentication).');

            if (!$this->api->authApiEnabled()) {
                if ($this->config->has('api.mfa_setup_url')) {
                    $this->stdErr->writeln(\sprintf(
                        'Ensure that MFA is enabled on your account. Set it up at: <comment>%s</comment>',
                        $this->config->get('api.mfa_setup_url')
                    ));
                    $this->stdErr->writeln(\sprintf('Then log in again with: <comment>%s login -f</comment>', $executable));
                } else {
                    $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
                }
            } elseif ($this->api->getUser()->mfa_enabled) {
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

        if ($this->authenticationSucceeded($failedProcess)) {
            return;
        }

        if ($this->hostKeyVerificationFailed($failedProcess)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('SSH was unable to verify the host key.');
            if ($host = $this->getHost($uri)) {
                $this->stdErr->writeln('In non-interactive environments, you can install the host key with:');
                $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg($host) . ' >> $HOME/.ssh/known_hosts');
                if (\strpos($host, 'ssh.') === 0) {
                    $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg('git.' . \substr($host, 4)) . ' >> $HOME/.ssh/known_hosts');
                } elseif (\strpos($host, 'git.') === 0) {
                    $this->stdErr->writeln('ssh-keyscan ' . \escapeshellarg('ssh.' . \substr($host, 4)) . ' >> $HOME/.ssh/known_hosts');
                }
            }
            return;
        }

        if ($this->keyAuthenticationFailed($failedProcess) && !$this->sshKey->hasLocalKey()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('The SSH connection failed.');
            if (!$this->certifier->isAutoLoadEnabled() && !$this->certifier->getExistingCertificate() && $this->config->isCommandEnabled('ssh-cert:load')) {
                $this->stdErr->writeln(\sprintf(
                    'You may need to create an SSH certificate, by running: <comment>%s ssh-cert:load</comment>',
                    $executable
                ));
                return;
            }
            if ($this->config->isCommandEnabled('ssh-key:add')) {
                $this->stdErr->writeln(\sprintf(
                    'You may need to add an SSH key, by running: <comment>%s ssh-key:add</comment>',
                    $executable
                ));
            }
        }
    }

    /**
     * Diagnoses an SSH command failure, making a new test connection, if practical.
     *
     * Nothing will happen if it's not practical or not relevant.
     *
     * @param string $uri
     *   The SSH URI (e.g. user@example.com).
     * @param int $startTime
     *   The start time of the original SSH attempt. Used to avoid running a test if too much time has passed.
     * @param int $exitCode
     *   The exit code of the SSH command. Used to check if diagnostics are relevant.
     */
    public function diagnoseFailureWithTest($uri, $startTime, $exitCode)
    {
        if ($exitCode !== self::_SSH_ERROR_EXIT_CODE || !$this->sshHostIsInternal($uri)) {
            return;
        }
        // Do not make a test connection if too much time has passed. More than 3 seconds would indicate either some
        // successful transfer or interaction taking place, or a connection that is so slow that a test would be
        // excessively annoying.
        if ($startTime !== 0 && \time() - $startTime > 3) {
            return;
        }
        // Do not make a test connection if the user has somehow been logged out.
        if (!$this->api->isLoggedIn()) {
            return;
        }
        $failedProcess = $this->testConnection($uri);
        $this->diagnoseFailure($uri, $failedProcess);
    }
}
