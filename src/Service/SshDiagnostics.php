<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Event\LoginRequiredEvent;
use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SshDiagnostics
{
    public const _SSH_ERROR_EXIT_CODE = 255;
    public const _GIT_SSH_ERROR_EXIT_CODE = 128;
    private readonly OutputInterface $stdErr;

    public function __construct(private readonly Ssh $ssh, OutputInterface $output, private readonly Certifier $certifier, private readonly SshKey $sshKey, private readonly Api $api, private readonly Config $config)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Checks whether SSH authentication succeeded, but the service doesn't exist or the user does not have access.
     *
     * @param Process $failedProcess
     *
     * @return string
     */
    private function noServiceAccessMessage(Process $failedProcess): string
    {
        $errorOutput = $failedProcess->getErrorOutput();
        if (stripos($errorOutput, "you successfully authenticated") !== false) {
            if (preg_match('/^Hello[^,]+, you successfully authenticated, but could not connect to service [^ ]+ \(reason: service doesn\'t exist or you do not have access to it\)$/m', $errorOutput, $matches)) {
                return $matches[0];
            }
        }
        return '';
    }

    /**
     * Returns step-up authentication parameters in the SSH error response.
     *
     * @param Process $failedProcess
     *
     * @return array{amr?: string[], max_age?: int}
     */
    private function stepUpAuthenticationParams(Process $failedProcess): array
    {
        $errorOutput = $failedProcess->getErrorOutput();
        if (!str_contains($errorOutput, 'Error: Access denied')) {
            return [];
        }
        if (preg_match('/^Parameters: ({.+)$/m', $errorOutput, $matches)) {
            $params = json_decode($matches[1], true);
            return $params ?: [];
        }
        return [];
    }

    /**
     * Checks whether the SSH connection failed due to MFA requirements.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function connectionFailedDueToMFA(Process $failedProcess): bool
    {
        return stripos($failedProcess->getErrorOutput(), 'reason: access requires MFA') !== false;
    }

    /**
     * Checks if SSH host key verification failed.
     *
     * @param Process $failedProcess
     *
     * @return bool
     */
    private function hostKeyVerificationFailed(Process $failedProcess): bool
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
    private function keyAuthenticationFailed(Process $failedProcess): bool
    {
        return stripos($failedProcess->getErrorOutput(), "Permission denied (publickey)") !== false;
    }

    /**
     * Tests the SSH connection.
     *
     * @param string $uri
     * @param float|int $timeout
     *
     * @return Process
     *   A process (already run) that tested the SSH connection.
     */
    private function testConnection(string $uri, float|int $timeout = 5): Process
    {
        $this->stdErr->writeln('Making test connection to diagnose SSH errors', OutputInterface::VERBOSITY_DEBUG);
        $process = Process::fromShellCommandline($this->ssh->getSshCommand($uri, [], 'exit', false, false), null, $this->ssh->getEnv());
        $process->setTimeout($timeout);
        $process->run();
        $this->stdErr->writeln('Test connection complete', OutputInterface::VERBOSITY_DEBUG);
        return $process;
    }

    /**
     * Checks whether the methods match between a step-up authentication challenge and the current auth methods.
     *
     * @param string[] $challengeMethods
     * @param string[] $currentMethods
     * @return bool
     */
    private function authMethodsMatch(array $challengeMethods, array $currentMethods): bool
    {
        $unmatched = array_diff($challengeMethods, $currentMethods);
        if (in_array('sso:*', $currentMethods, true)) {
            foreach ($unmatched as $key => $method) {
                if (str_starts_with($method, 'sso:')) {
                    unset($unmatched[$key]);
                }
            }
        }
        return $unmatched === [];
    }

    /**
     * Diagnoses and reports reasons for an SSH command failure.
     *
     * @param string $uri
     *   The SSH connection URI.
     * @param Process $failedProcess
     *   The failed SSH process.
     * @param bool $newline
     *   Whether to add a new line before messages.
     */
    public function diagnoseFailure(string $uri, Process $failedProcess, bool $newline = true): void
    {
        if (!$this->ssh->hostIsInternal($uri)) {
            return;
        }
        $cmdLine = $failedProcess->getCommandLine();
        $cmdName = trim(explode(' ', $cmdLine, 2)[0], '"\'');
        $exitCode = $failedProcess->getExitCode();
        if ($cmdName === 'git') {
            if ($exitCode !== self::_GIT_SSH_ERROR_EXIT_CODE) {
                return;
            }
        } elseif ($exitCode !== self::_SSH_ERROR_EXIT_CODE) {
            return;
        }

        $executable = $this->config->getStr('application.executable');

        if ($params = $this->stepUpAuthenticationParams($failedProcess)) {
            if ($newline) {
                $this->stdErr->writeln('');
            }

            // Check if the step-up authentication challenge occurred just
            // because the current token was revoked. This may be the case if
            // the certificate's auth methods match those required in the
            // challenge. If so, refresh the certificate.
            if (($cert = $this->certifier->getExistingCertificate())) {
                $claims = $cert->tokenClaims();
                if (isset($params['amr'], $claims['amr']) && $this->authMethodsMatch($params['amr'], $claims['amr'])) {
                    $this->stdErr->writeln('The SSH certificate is out of date. Refreshing...');
                    $this->certifier->generateCertificate($cert);
                    $this->stdErr->writeln('Please try again.');
                    return;
                }
            }

            $loginRequiredEvent = new LoginRequiredEvent($params['amr'] ?? [], $params['max_age'] ?? null, $this->api->hasApiToken());
            $this->stdErr->writeln($loginRequiredEvent->getExtendedMessage($this->config));
            return;
        }

        $mfaVerified = ($cert = $this->certifier->getExistingCertificate()) && $cert->hasMfa();
        if (!$mfaVerified && $this->connectionFailedDueToMFA($failedProcess)) {
            if ($newline) {
                $this->stdErr->writeln('');
            }

            if (!$this->certifier->getExistingCertificate() && !$this->certifier->isAutoLoadEnabled()) {
                $this->stdErr->writeln('The SSH connection failed. An SSH certificate is required.');
                $this->stdErr->writeln(sprintf('Generate one using: <comment>%s ssh-cert:load</comment>', $executable));
                return;
            }

            $this->stdErr->writeln('The SSH connection failed because access requires MFA (multi-factor authentication).');

            $user = $this->api->getUser();
            if ($user->mfa_enabled) {
                $this->stdErr->writeln('MFA is currently enabled on your account, but reverification is required.');
                $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
                return;
            }

            if ($user->sso_enabled) {
                $this->stdErr->writeln('Reverification may be required.');
                $this->stdErr->writeln(\sprintf('Log in again with: <comment>%s login -f</comment>', $executable));
                return;
            }

            $this->stdErr->writeln('MFA is not yet enabled on your account.');
            if ($this->config->has('api.mfa_setup_url')) {
                $this->stdErr->writeln(\sprintf('Set up MFA at: <comment>%s</comment>', $this->config->getStr('api.mfa_setup_url')));
                $this->stdErr->writeln(\sprintf('Then log in again with: <comment>%s login -f</comment>', $executable));
                return;
            }

            $this->stdErr->writeln(\sprintf('Set up MFA, then log in again with: <comment>%s login -f</comment>', $executable));
            return;
        }

        if ($this->hostKeyVerificationFailed($failedProcess)) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('SSH was unable to verify the host key.');
            return;
        }

        if ($msg = $this->noServiceAccessMessage($failedProcess)) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('SSH authentication worked, but there was an access problem:');
            $this->stdErr->writeln('  ' . $msg);
            return;
        }

        if ($this->keyAuthenticationFailed($failedProcess) && !$this->sshKey->hasLocalKey()) {
            if ($newline) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('The SSH connection failed.');
            if (!$this->certifier->isAutoLoadEnabled() && !$this->certifier->getExistingCertificate() && $this->config->isCommandEnabled('ssh-cert:load')) {
                $this->stdErr->writeln(\sprintf(
                    'You may need to create an SSH certificate, by running: <comment>%s ssh-cert:load</comment>',
                    $executable,
                ));
                return;
            }
            if ($this->config->isCommandEnabled('ssh-key:add') && !$this->certifier->isAutoLoadEnabled() && !$this->certifier->getExistingCertificate()) {
                $this->stdErr->writeln(\sprintf(
                    'You may need to add an SSH key, by running: <comment>%s ssh-key:add</comment>',
                    $executable,
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
    public function diagnoseFailureWithTest(string $uri, int $startTime, int $exitCode): void
    {
        if ($exitCode !== self::_SSH_ERROR_EXIT_CODE || !$this->ssh->hostIsInternal($uri)) {
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
