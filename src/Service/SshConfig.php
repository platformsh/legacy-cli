<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;

class SshConfig {
    private $config;
    private $fs;
    private $stdErr;
    private $sshKey;
    private $certifier;
    private $openSshVersion;

    public function __construct(Config $config, Filesystem $fs, OutputInterface $output, SshKey $sshKey, Certifier $certifier)
    {
        $this->config = $config;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshKey = $sshKey;
        $this->certifier = $certifier;
    }

    /**
     * Creates or updates config files with known host keys.
     *
     * @return string|null
     *   The path to the file containing the host keys, if any.
     */
    public function configureHostKeys()
    {
        $hostKeys = '';
        if ($hostKeysFile = $this->config->getWithDefault('ssh.host_keys_file', '')) {
            $hostKeysFile = CLI_ROOT . DIRECTORY_SEPARATOR . $hostKeysFile;
            $hostKeys = file_get_contents($hostKeysFile);
            if ($hostKeys === false) {
                trigger_error('Failed to load host keys file: ' . $hostKeysFile, E_USER_WARNING);
                return null;
            }
        }
        if ($additionalKeys = $this->config->getWithDefault('ssh.host_keys', [])) {
            if (!is_array($additionalKeys)) {
                $additionalKeys = explode("\n", $additionalKeys);
            }
            $hostKeys = rtrim($hostKeys, "\n") . "\n" . $additionalKeys;
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
            $this->writeSshIncludeFile($keysConfigFile, ['UserKnownHostsFile ~/.ssh/known_hosts ~/.ssh/known_hosts2 ' . $this->formatFilePath($keysFile)]);
        }

        return $keysFile;
    }

    /**
     * Creates or updates session-specific SSH configuration.
     *
     * @return bool
     *   True if there is any session configuration, false otherwise.
     */
    public function configureSessionSsh()
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
            $executable = $this->config->get('application.executable');
            $refreshCommand = sprintf('%s ssh-cert:load --refresh-only --yes', $executable);

            // On shells where it might work (POSIX compliant), skip refreshing
            // the certificate when the CLI_SSH_NO_REFRESH env var is set to 1.
            // This skips unnecessary CLI bootstrapping while running SSH from
            // the CLI itself.
            /** @see Ssh::getEnv() */
            if (in_array(basename(getenv('SHELL')), ['bash', 'csh', 'dash', 'ksh', 'tcsh', 'zsh'], true)) {
                // Literal double-quotes do not appear to be possible in SSH config.
                // See: https://bugzilla.mindrot.org/show_bug.cgi?id=3474
                // So the condition here uses single quotes after the variable
                // to allow it to be treated as an empty string when not set.
                $refreshCommand = sprintf("[ \$%s'' = '1' ] || %s", Ssh::SSH_NO_REFRESH_ENV_VAR, $refreshCommand);
            }

            if (!OsUtil::isWindows()) {
                $refreshCommand .= ' 2>/dev/null';
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
                $lines[] = sprintf('CertificateFile %s', $this->formatFilePath($certificate->certificateFilename()));
            } else {
                $lines[] = '# Include the certificate, via its key.';
                $lines[] = '# The CertificateFile keyword could be used with OpenSSH 7.2 or later.';
            }
            $lines[] = sprintf('IdentityFile %s', $this->formatFilePath($certificate->privateKeyFilename()));
        } else {
            $lines[] = 'Host ' . implode(' ', $domainWildcards);

            $sessionIdentityFile = $this->sshKey->selectIdentity();
            if ($sessionIdentityFile !== null) {
                $lines[] = '';
                $lines[] = '# This SSH key was detected as corresponding to the session.';
                $lines[] = sprintf('IdentityFile %s', $this->formatFilePath($sessionIdentityFile));
                $lines[] = '';
            }
        }

        $sessionSpecificFilename = $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config';
        $includerFilename = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config';
        if (empty($lines)) {
            if (\file_exists($includerFilename) || \file_exists($sessionSpecificFilename)) {
                $this->fs->remove([$includerFilename, $sessionSpecificFilename]);
            }
            return false;
        }

        // Add other configured options.
        if ($configuredOptions = $this->config->get('ssh.options')) {
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
        $includerLines[] = '  Include ' . $sessionSpecificFilename;
        $this->writeSshIncludeFile(
            $includerFilename,
            $includerLines
        );

        return true;
    }

    /**
     * Formats a file path for an SSH config option.
     *
     * This should be applied to the IdentityFile and CertificateFile option
     * values. See the ssh_config(5) man page: https://www.freebsd.org/cgi/man.cgi?ssh_config%285%29
     *
     * Note: a previous version of this method replaced the home directory with
     * SSH's percent syntax (%d). However, this does not work on systems where
     * the HOME environment variable is set to a directory other than the
     * current user's (notably on GitHub Actions containers), because the
     * OpenSSH client does not support reading HOME.
     *
     * @param string $path
     *
     * @return string
     */
    public function formatFilePath($path)
    {
        // Convert absolute Windows paths (e.g. beginning "C:\") to Unix paths.
        // OpenSSH apparently treats the Windows format as a relative path.
        if (OsUtil::isWindows() && $this->config->getWithDefault('ssh.convert_windows_paths', true)) {
            if (\strlen($path) >= 2 && $path[1] === ':' && \preg_match('#^[A-Z]#', $path)) {
                $path = '/' . \strtolower($path[0]) . '/' . \ltrim(\substr($path, 2), '\\/');
                $path = \str_replace('\\', '/', $path);
            }
        }

        // Quote all paths containing a space.
        if (\strpos($path, ' ') !== false) {
            // The three quote marks in the middle mean: end quote, literal quote mark, start quote.
            $path = '"' . \str_replace('"', '"""', $path) . '"';
        }

        return $path;
    }

    /**
     * Creates or updates an SSH config include file.
     *
     * @param string $filename
     * @param array|string $lines
     * @param bool   $allowDelete
     */
    private function writeSshIncludeFile($filename, $lines, $allowDelete = true)
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
        $this->chmod($filename, 0600);
    }

    /**
     * Returns the directory containing session-specific SSH configuration.
     *
     * @return string
     */
    public function getSessionSshDir()
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
    public function addUserSshConfig(QuestionHelper $questionHelper)
    {
        if (!$this->supportsInclude()) {
            return false;
        }

        $filename = $this->getUserSshConfigFilename();

        $wildcards = $this->config->getWithDefault('ssh.domain_wildcards', []);
        if (!$wildcards) {
            return false;
        }

        $suggestedConfig = \implode("\n", [
            'Host ' . \implode(' ', $wildcards),
            '  Include ' . $this->getCliSshDir() . DIRECTORY_SEPARATOR . '*.config',
            'Host *',
        ]);

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
            if (strpos($currentContents, $suggestedConfig) !== false) {
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

        $this->chmod($filename, 0600);

        return true;
    }

    /**
     * Returns whether the user's SSH configuration should be written to.
     *
     * @return bool|null
     *   True for yes, false for no, null to prompt the user.
     */
    private function shouldWriteUserSshConfig()
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
    public function deleteSessionConfiguration()
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
    private function chmod($filename, $mode)
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
    private function getCliSshDir()
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
    private function getUserSshConfigChanges($currentConfig, $newConfig)
    {
        $serviceName = (string)$this->config->get('service.name');
        $begin = '# BEGIN: ' . $serviceName . ' certificate configuration' . PHP_EOL;
        $end = PHP_EOL . '# END: ' . $serviceName . ' certificate configuration';

        return (new Snippeter())->updateSnippet($currentConfig, $newConfig, $begin, $end);
    }

    /**
     * Returns the path to the user's global SSH config file.
     *
     * @return string
     */
    private function getUserSshConfigFilename()
    {
        return $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Removes certificate configuration from the user's global SSH config file.
     *
     * @todo use this? maybe in an uninstall command
     */
    public function removeUserSshConfig()
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
    private function findVersion($reset = false)
    {
        if (isset($this->openSshVersion) && !$reset) {
            return $this->openSshVersion;
        }
        $this->openSshVersion = false;
        $process = new Process('ssh -V');
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
    private function versionIsBelow($test)
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
    public function supportsInclude() {
        return !$this->versionIsBelow('7.3');
    }

    /**
     * Checks if the installed OpenSSH version supports the 'CertificateFile' syntax.
     *
     * @return bool
     */
    public function supportsCertificateFile() {
        return !$this->versionIsBelow('7.2');
    }

    /**
     * Checks if the required version is supported, and prints a warning.
     *
     * @return bool
     *   False if an incorrect version is installed, true otherwise.
     */
    public function checkRequiredVersion()
    {
        $version = $this->findVersion();
        if (!$version) {
            return true;
        }
        if (\version_compare($version, '6.5', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <error>%s</error> is installed. Version 6.5 or above is required. Some features depend on version 7.3 or above.',
                $version
            ));
            return false;
        }
        if (\version_compare($version, '7.3', '<')) {
            $this->stdErr->writeln(\sprintf(
                'OpenSSH version <comment>%s</comment> is installed. Some features depend on version 7.3 or above.',
                $version
            ));
        }
        return true;
    }
}
