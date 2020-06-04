<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certificate;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;

class SshConfig {
    private $config;
    private $fs;
    private $stdErr;
    private $keySelector;

    public function __construct(Config $config, Filesystem $fs, OutputInterface $output, KeySelector $keySelector)
    {
        $this->config = $config;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->keySelector = $keySelector;
    }

    /**
     * Creates or updates session-specific SSH configuration.
     *
     * @param Certificate|null $certificate
     *
     * @return bool
     *   True if there is any session configuration, false otherwise.
     */
    public function configureSessionSsh(Certificate $certificate = null)
    {
        $lines = [];

        if ($certificate) {
            $executable = $this->config->get('application.executable');
            $refreshCommand = sprintf('%s ssh-cert:load --refresh-only --yes --quiet', $executable);
            $lines[] = sprintf('Match host %s exec "%s"', $this->config->get('api.ssh_domain_wildcard'), $refreshCommand);
            $lines[] = sprintf('  CertificateFile %s', $certificate->certificateFilename());
            $lines[] = sprintf('  IdentityFile %s', $certificate->privateKeyFilename());
        }

        if (empty($lines)) {
            $this->deleteSessionConfiguration();
            return false;
        }

        // Add the preferred session identity file first, and then the default
        // files.
        $sessionIdentityFile = $this->keySelector->getIdentityFile();
        $defaultFiles = $this->getUserDefaultSshIdentityFiles();
        foreach (\array_filter(\array_unique(\array_merge([$sessionIdentityFile], $defaultFiles))) as $identityFile) {
            $lines[] = sprintf('IdentityFile %s', $identityFile);
        }

        $sessionSpecificFilename = $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config';
        $this->writeSshIncludeFile($sessionSpecificFilename, $lines);
        $this->writeSshIncludeFile(
            $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config',
            [
                '# Include the SSH config file for the active session.',
                'Include ' . $sessionSpecificFilename,
            ]
        );

        // Backwards compatibility: delete the old SSH configuration file.
        $legacy = $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'sess-cli-default.config';
        if (\file_exists($legacy)) {
            $this->fs->remove($legacy);
        }

        return true;
    }

    /**
     * Creates or updates an SSH config include file.
     *
     * @param string $filename
     * @param array  $lines
     * @param bool   $allowDelete
     */
    private function writeSshIncludeFile($filename, array $lines, $allowDelete = true)
    {
        if (empty($lines) && $allowDelete && \file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Deleting SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->remove($filename);
            return;
        }
        $contents = implode(PHP_EOL, $lines) . PHP_EOL;
        if (!\file_exists($filename)) {
            $this->stdErr->writeln(sprintf('Creating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } elseif (\file_get_contents($filename) !== $contents) {
            $this->stdErr->writeln(sprintf('Updating SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
            $this->fs->writeFile($filename, $contents, false);
        } else {
            $this->stdErr->writeln(sprintf('Validated SSH configuration include file: <info>%s</info>', $filename), OutputInterface::VERBOSITY_VERBOSE);
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
        $filename = $this->getUserSshConfigFilename();

        $suggestedConfig = 'Host ' . $this->config->get('api.ssh_domain_wildcard') . PHP_EOL
            . '  Include ' . $this->getCliSshDir() . DIRECTORY_SEPARATOR . '*.config';

        $manualMessage = 'To configure SSH manually, add the following lines to: <comment>' . $filename . '</comment>'
            . "\n" . $suggestedConfig;

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
            if (!$questionHelper->confirm('Do you want to update the file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $creating = false;
        } else {
            if (!$questionHelper->confirm('Do you want to create an SSH configuration file automatically?')) {
                $this->stdErr->writeln($manualMessage);
                return false;
            }
            $currentContents = '';
            $creating = true;
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
     * Deletes session-specific SSH configuration files.
     */
    public function deleteSessionConfiguration()
    {
        $files = [
            $this->getSessionSshDir() . DIRECTORY_SEPARATOR . 'config',
            $this->getCliSshDir() . DIRECTORY_SEPARATOR . 'session.config',
        ];
        if (array_filter($files, '\\file_exists') !== []) {
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
     * Returns the user's default SSH identity files (if they exist).
     *
     * @todo prioritise account-specific identity files
     *
     * These are the filenames that are used by SSH when there is no
     * IdentityFile specified, and when there is no SSH agent.
     *
     * @see https://man.openbsd.org/ssh#i
     *
     * @return array
     */
    public function getUserDefaultSshIdentityFiles()
    {
        $dir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';
        if (!\is_dir($dir)) {
            return [];
        }
        $basenames = [
            'id_rsa',
            'id_ecdsa_sk',
            'id_ecdsa',
            'id_ed25519_sk',
            'id_ed25519',
            'id_dsa',
        ];
        $files = [];
        foreach ($basenames as $basename) {
            $filename = $dir . DIRECTORY_SEPARATOR . $basename;
            if (\file_exists($filename) && \file_exists($filename . '.pub')) {
                $files[] = $filename;
            }
        }

        return $files;
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
}
