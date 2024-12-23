<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;

class SshConfig
{
    private readonly OutputInterface $stdErr;
    private false|string|null $openSshVersion = null;

    public function __construct(private readonly Config $config, private readonly Filesystem $fs, OutputInterface $output, private readonly SshKey $sshKey, private readonly Certifier $certifier)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Creates or updates config files with known host keys.
     *
     * @return string|null
     *   The path to the file containing the host keys, if any.
     */
    public function configureHostKeys(): ?string
    {
        $hostKeys = '';
        if ($hostKeysFile = $this->config->getStr('ssh.host_keys_file')) {
            $hostKeysFile = CLI_ROOT . DIRECTORY_SEPARATOR . $hostKeysFile;
            $hostKeys = file_get_contents($hostKeysFile);
            if ($hostKeys === false) {
                trigger_error('Failed to load host keys file: ' . $hostKeysFile, E_USER_WARNING);
                return null;
            }
        }
        if ($additionalKeys = $this->config->getWithDefault('ssh.host_keys', [])) {
            if (!is_array($additionalKeys)) {
                $additionalKeys = explode("\n", (string) $additionalKeys);
            }
            $hostKeys = rtrim($hostKeys, "\n") . "\n" . implode("\n", $additionalKeys);
        }
        if (empty($hostKeys)) {
            return null;
        }

        // Write the keys.
        $keysFile = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'host-keys';
        $this->writeSshIncludeFile($keysFile, $hostKeys);

        // Write the config.
        // Any .config files will be included automatically as SSH config.
        if ($this->supportsInclude()) {
            $keysConfigFile = $keysFile . '.config';
            $this->writeSshIncludeFile($keysConfigFile, ['UserKnownHostsFile ~/.ssh/known_hosts ~/.ssh/known_hosts2 ' . implode(' ', $this->formattedPaths($keysFile))]);
        }

        return $keysFile;
    }

    /**
     * Creates or updates session-specific SSH configuration.
     *
     * @return bool
     *   True if there is any session configuration, false otherwise.
     */
    public function configureSessionSsh(): bool
    {
        if (!$this->supportsInclude()) {
            return false;
        }

        // Backwards compatibility: delete the old SSH configuration file.
        $legacy = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'sess-cli-default.config';
        if (\file_exists($legacy)) {
            $this->fs->remove($legacy);
        }

        $domainWildcards = $this->config->getWithDefault('ssh.domain_wildcards', []);
        if (!$domainWildcards) {
            return false;
        }

        $lines = [];

        $certificate = $this->certifier->getExistingCertificate();
        if ($certificate) {
            $executable = $this->config->getStr('application.executable');
            $refreshCommand = sprintf('%s ssh-cert:load --refresh-only --yes --quiet', $executable);

            // On shells where it might work (POSIX compliant), skip refreshing
            // the certificate when the CLI_SSH_NO_REFRESH env var is set to 1.
            // This skips unnecessary CLI bootstrapping while running SSH from
            // the CLI itself.
            /** @see Ssh::getEnv() */
            if (in_array(basename((string) getenv('SHELL')), ['bash', 'csh', 'dash', 'ksh', 'tcsh', 'zsh'], true)) {
                // Literal double-quotes do not appear to be possible in SSH config.
                // See: https://bugzilla.mindrot.org/show_bug.cgi?id=3474
                // So the condition here uses single quotes after the variable
                // to allow it to be treated as an empty string when not set.
                $refreshCommand = sprintf("[ \$%s'' = '1' ] || %s", Ssh::SSH_NO_REFRESH_ENV_VAR, $refreshCommand);
            }

            // Use Match solely to run the refresh command.
            $lines[] = '# Auto-refresh the SSH certificate.';
            $lines[] = sprintf('Match host "%s" exec "%s"', \implode(',', $domainWildcards), $refreshCommand);

            $lines[] = '';
            $lines[] = '# Cancel the Match, so that the following configuration will apply regardless of';
            $lines[] = "# the command's execution.";
            $lines[] = 'Host ' . implode(' ', $domainWildcards);

            $lines[] = '';
            if ($this->supportsCertificateFile()) {
                $lines[] = '# Include the certificate and its key.';
                foreach ($this->formattedPaths($certificate->certificateFilename()) as $path) {
                    $lines[] = 'CertificateFile ' . $path;
                }
            } else {
                $lines[] = '# Include the certificate, via its key.';
                $lines[] = '# The CertificateFile keyword could be used with OpenSSH 7.2 or later.';
            }
            foreach ($this->formattedPaths($certificate->privateKeyFilename()) as $path) {
                $lines[] = 'IdentityFile ' . $path;
            }
        } else {
            $lines[] = 'Host ' . implode(' ', $domainWildcards);

            $sessionIdentityFile = $this->sshKey->selectIdentity();
            if ($sessionIdentityFile !== null) {
                $lines[] = '';
                $lines[] = '# This SSH key was detected as corresponding to the session.';
                foreach ($this->formattedPaths($sessionIdentityFile) as $path) {
                    $lines[] = 'IdentityFile ' . $path;
                }
                $lines[] = '';
            }
        }

        $sessionSpecificFilename = $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config';
        $includerFilename = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config';

        // Add other configured options.
        if ($configuredOptions = $this->config->get('ssh.options')) {
            /** @var string[]|string $configuredOptions */
            $lines[] = '';
            $lines[] = "# Other options from the CLI's configuration.";
            $lines = array_merge($lines, is_array($configuredOptions) ? $configuredOptions : explode("\n", $configuredOptions));
        }

        $this->writeSshIncludeFile($sessionSpecificFilename, $lines);

        $includerLines = [
            '# This file is included from your SSH config file (~/.ssh/config).',
            '# In turn, it includes the configuration for the currently active CLI session.',
            '# It is updated automatically when certain CLI commands are run.',
        ];

        $includerLines[] = 'Host ' . implode(' ', $domainWildcards);
        foreach ($this->formattedPaths($sessionSpecificFilename) as $path) {
            $includerLines[] = '  Include ' . $path;
        }
        $this->writeSshIncludeFile(
            $includerFilename,
            $includerLines,
        );

        return true;
    }

    /**
     * Transforms a file path into a list of formatted paths.
     *
     * More than one path format may be used for shell compatibility, which is
     * why this returns an array.
     *
     * @param string $path
     *
     * @return string[]
     */
    public function formattedPaths(string $path): array
    {
        // Convert absolute Windows paths (e.g. beginning "C:\") to Unix paths.
        // OpenSSH apparently treats the Windows format as a relative path.
        $isWindowsPath = OsUtil::isWindows() && \strlen($path) >= 2 && $path[1] === ':' && \preg_match('#^[A-Z]#', $path);
        if ($isWindowsPath) {
            $convertedPath = '/' . \strtolower($path[0]) . '/' . \ltrim(\substr($path, 2), '\\/');
            $convertedPath = \str_replace('\\', '/', $convertedPath);
            switch ($setting = $this->config->getWithDefault('ssh.windows_paths', 'both')) {
                case 'raw':
                    return [$this->quoteFilePath($path)];

                case 'windows':
                    return [$this->quoteFilePath($convertedPath)];

                case 'both':
                    return [$this->quoteFilePath($convertedPath), $this->quoteFilePath($path)];

                default:
                    trigger_error(sprintf('Invalid configuration value for ssh.windows_paths: %s (expected "both", "windows", or "raw)', $setting), E_USER_WARNING);
                    return [$this->quoteFilePath($convertedPath), $this->quoteFilePath($path)];
            }
        }

        return [$this->quoteFilePath($path)];
    }

    /**
     * Quotes a file path for an SSH config option.
     *
     * This should be applied to the IdentityFile and CertificateFile option
     * values. See the ssh_config(5) man page: https://www.freebsd.org/cgi/man.cgi?ssh_config%285%29
     *
     * Note: a previous version of this method replaced the home directory with
     * SSH's percent syntax (%d). However, this does not work on systems where
     * the HOME environment variable is set to a directory other than the
     * current user's (notably on GitHub Actions containers), because the
     * OpenSSH client does not support reading HOME.
     */
    private function quoteFilePath(string $path): string
    {
        // Quote all paths containing a space.
        if (str_contains($path, ' ')) {
            // The three quote marks in the middle mean: end quote, literal quote mark, start quote.
            return '"' . \str_replace('"', '"""', $path) . '"';
        }

        return $path;
    }

    /**
     * Creates or updates an SSH config include file.
     *
     * @param string[]|string $lines
     */
    private function writeSshIncludeFile(string $filename, array|string $lines, bool $allowDelete = true): void
    {
        if (empty($lines) && $allowDelete && \file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Deleting SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->remove($filename);
            return;
        }
        $contents = is_array($lines) ? implode(PHP_EOL, $lines) . PHP_EOL : $lines;
        if (!\file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Creating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } elseif (\file_get_contents($filename) !== $contents) {
            $this->stdErr->writeln(sprintf('Updating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } else {
            $this->stdErr->writeln(sprintf('Validated SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
        $this->chmod($filename, 0o600);
    }

    /**
     * Returns the directory containing session-specific SSH configuration.
     *
     * @return string
     */
    public function getSessionSshDir(): string
    {
        return $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
    }

    /**
     * Adds configuration to the user's global SSH config file (~/.ssh/config).
     *
     * @param QuestionHelper $questionHelper
     *
     * @return bool
     */
    public function addUserSshConfig(QuestionHelper $questionHelper): bool
    {
        if (!$this->supportsInclude()) {
            return false;
        }

        $filename = $this->getUserSshConfigFilename();

        $wildcards = $this->config->getWithDefault('ssh.domain_wildcards', []);
        if (!$wildcards) {
            return false;
        }

        $lines = [];
        $lines[] = 'Host ' . \implode(' ', $wildcards);
        foreach ($this->formattedPaths($this->getCliSshDir() . DIRECTORY_SEPARATOR . '*.config') as $path) {
            $lines[] = '  Include ' . $path;
        }
        $lines[] = 'Host *';

        $suggestedConfig = \implode("\n", $lines);

        $manualMessage = 'To configure SSH, add the following lines to <comment>' . $filename . '</comment>:'
            . "\n" . $suggestedConfig;

        $writeUserSshConfig = $this->shouldWriteUserSshConfig();
        if ($writeUserSshConfig === false) {
            $this->stdErr->writeln($manualMessage);
            return true;
        }

        if (file_exists($filename)) {
            $currentContents = file_get_contents($filename);
            if ($currentContents === false) {
                $this->stdErr->writeln('Failed to read file: <comment>' . $filename . '</comment>');
                return false;
            }
            if (str_contains($currentContents, $suggestedConfig)) {
                $this->stdErr->writeln('Validated SSH configuration file: <info>' . $filename . '</info>', OutputInterface::VERBOSITY_VERBOSE);
                return true;
            }
            $this->stdErr->writeln('Checking SSH configuration file: <info>' . $filename . '</info>');
            if ($writeUserSshConfig !== true && !$questionHelper->confirm('Do you want to update the file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $creating = false;
        } elseif ($this->fs->canWrite($filename)) {
            if ($writeUserSshConfig !== true && !$questionHelper->confirm('Do you want to create an SSH configuration file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $currentContents = '';
            $creating = true;
        } else {
            $this->stdErr->writeln($manualMessage);
            return false;
        }

        $newContents = $this->getUserSshConfigChanges($currentContents, $suggestedConfig);
        $this->fs->writeFile($filename, $newContents);

        if ($creating) {
            $this->stdErr->writeln('Configuration file created successfully: <info>' . $filename . '</info>');
        } else {
            $this->stdErr->writeln('Configuration file updated successfully: <info>' . $filename . '</info>');
        }

        $this->chmod($filename, 0o600);

        return true;
    }

    /**
     * Returns whether the user's SSH configuration should be written to.
     *
     * @return bool|null
     *   True for yes, false for no, null to prompt the user.
     */
    private function shouldWriteUserSshConfig(): ?bool
    {
        $value = $this->config->has('api.write_user_ssh_config')
            ? $this->config->get('api.write_user_ssh_config')
            : $this->config->getWithDefault('ssh.write_user_config', null);

        // Consider an empty string (or null) as null, otherwise cast to bool.
        //
        // The original YAML config may have been overridden by an environment
        // variable (which is how it could be a string).
        // TODO this could be replaced if the config had schema validation
        return $value === null || $value === '' ? null : (bool) $value;
    }

    /**
     * Deletes session-specific SSH configuration files.
     *
     * Called from the logout command.
     */
    public function deleteSessionConfiguration(): void
    {
        $files = [
            $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config',
            $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config',
        ];
        if (array_filter($files, '\\file_exists') !== []) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Deleting all session SSH configuration');
            $this->fs->remove($files);
        }
    }

    /**
     * Change file permissions and emit a warning on failure.
     *
     * @param string $filename
     * @param int $mode
     *
     * @return bool
     */
    private function chmod(string $filename, int $mode): bool
    {
        if (!@chmod($filename, $mode)) {
            $this->stdErr->writeln('Warning: failed to change permissions on file: <comment>' . $filename . '</comment>');
            return false;
        }
        return true;
    }

    /**
     * Returns the directory for CLI-specific SSH configuration files.
     *
     * @return string
     */
    private function getCliSshDir(): string
    {
        return $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . 'ssh';
    }

    /**
     * Calculates changes to a user's global SSH config file.
     *
     * @param string $currentConfig
     *   The current file contents.
     * @param string $newConfig
     *   The new config that should be inserted. Pass an empty string to delete
     *   the configuration.
     *
     * @return string The new file contents.
     */
    private function getUserSshConfigChanges(string $currentConfig, string $newConfig): string
    {
        $serviceName = $this->config->getStr('service.name');
        $begin = '# BEGIN: ' . $serviceName . ' certificate configuration' . PHP_EOL;
        $end = PHP_EOL . '# END: ' . $serviceName . ' certificate configuration';

        return (new Snippeter())->updateSnippet($currentConfig, $newConfig, $begin, $end);
    }

    /**
     * Returns the path to the user's global SSH config file.
     *
     * @return string
     */
    private function getUserSshConfigFilename(): string
    {
        return $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Removes certificate configuration from the user's global SSH config file.
     *
     * @todo use this? maybe in an uninstall command
     */
    public function removeUserSshConfig(): void
    {
        $sshConfigFile = $this->getUserSshConfigFilename();
        if (!file_exists($sshConfigFile)) {
            return;
        }
        $currentSshConfig = file_get_contents($sshConfigFile);
        if ($currentSshConfig === false) {
            return;
        }
        $newConfig = $this->getUserSshConfigChanges($currentSshConfig, '');
        if ($newConfig === $currentSshConfig) {
            return;
        }
        $this->stdErr->writeln('Removing configuration from SSH configuration file: <info>' . $sshConfigFile . '</info>');

        try {
            $this->fs->writeFile($sshConfigFile, $newConfig);
            $this->stdErr->writeln('Configuration successfully removed');
        } catch (IOException $e) {
            $this->stdErr->writeln('The configuration could not be automatically removed: ' . $e->getMessage());
        }
    }

    /**
     * Finds the locally installed OpenSSH version.
     *
     * @param bool $reset
     *
     * @return string|false
     */
    private function findVersion(bool $reset = false): string|false
    {
        if (isset($this->openSshVersion) && !$reset) {
            return $this->openSshVersion;
        }
        $this->openSshVersion = false;
        $process = new Process(['ssh', '-V']);
        $process->run();
        $errorOutput = $process->getErrorOutput();
        if (!$process->isSuccessful()) {
            if ($this->stdErr->isVerbose()) {
                $this->stdErr->writeln('Unable to determine the installed OpenSSH version. The command output was:');
                $this->stdErr->writeln($errorOutput);
            }
            return false;
        }
        if (\preg_match('/OpenSSH_([0-9.]+[^ ,]*)/', $errorOutput, $matches)) {
            $this->openSshVersion = $matches[1];
        }
        return $this->openSshVersion;
    }

    /**
     * Checks if the installed OpenSSH version is below a given value.
     *
     * @param string $test
     *
     * @return bool
     *   True if the version is determined and it is lower than the $test value, false otherwise.
     */
    private function versionIsBelow(string $test): bool
    {
        $version = $this->findVersion();
        if (!$version) {
            return false;
        }
        return \version_compare($version, $test, '<');
    }

    /**
     * Checks if the installed OpenSSH version supports the 'Include' syntax.
     *
     * @return bool
     */
    public function supportsInclude(): bool
    {
        return !$this->versionIsBelow('7.3');
    }

    /**
     * Checks if the installed OpenSSH version supports the 'CertificateFile' syntax.
     *
     * @return bool
     */
    public function supportsCertificateFile(): bool
    {
        return !$this->versionIsBelow('7.2');
    }

    /**
     * Checks if the required version is supported, and prints a warning.
     *
     * @return bool
     *   False if an incorrect version is installed, true otherwise.
     */
    public function checkRequiredVersion(): bool
    {
        $version = $this->findVersion();
        if (!$version) {
            return true;
        }
        if (\version_compare($version, '6.5', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <error>%s</error> is installed. Version 6.5 or above is required. Some features depend on version 7.3 or above.',
                $version,
            ));
            return false;
        }
        if (\version_compare($version, '7.3', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <comment>%s</comment> is installed. Some features depend on version 7.3 or above.',
                $version,
            ));
        }
        return true;
    }
}
