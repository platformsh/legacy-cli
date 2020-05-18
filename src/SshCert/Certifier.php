<?php

namespace Platformsh\Cli\SshCert;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\Snippeter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;

class Certifier
{
    const PRIVATE_KEY_FILENAME = 'id_rsa';
    const CONFIG_FILENAME = 'config';

    private $api;
    private $config;
    private $shell;
    private $fs;
    private $stdErr;

    public function __construct(Api $api = null, Config $config = null, Shell $shell = null, Filesystem $fs = null, OutputInterface $output = null)
    {
        $this->api = $api ?: new Api();
        $this->config = $config ?: new Config();
        $this->shell = $shell ?: new Shell();
        $this->fs = $fs ?: new Filesystem();
        $this->stdErr = $output ? ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output) : new NullOutput();
    }

    /**
     * Returns whether automatic certificate loading (on login or SSH) is enabled.
     *
     * @return bool
     */
    public function isAutoLoadEnabled()
    {
        return (bool)$this->config->get('api.auto_load_ssh_cert');
    }

    /**
     * Generates a new certificate.
     *
     * @param bool $newKeyPair Whether to recreate the SSH key pair.
     *
     * @return Certificate
     */
    public function generateCertificate($newKeyPair = false)
    {
        $dir = $this->getCertificateDir();
        $this->fs->mkdir($dir, 0700);

        $sshPair = $this->generateSshKey($dir, $newKeyPair);
        $publicContents = file_get_contents($sshPair['public']);
        if (!$publicContents) {
            throw new \RuntimeException('Failed to read public key file: ' . $publicContents);
        }

        $this->stdErr->writeln('Requesting certificate from the API', OutputInterface::VERBOSITY_VERBOSE);
        $certificate = $this->requestCertificate($publicContents);

        $certificateFilename = $sshPair['private'] . '-cert.pub';
        $this->fs->writeFile($certificateFilename, $certificate);
        @chmod($certificateFilename, 0600);

        $this->stdErr->writeln('Generating include file for SSH configuration', OutputInterface::VERBOSITY_VERBOSE);
        $this->createSshConfig($certificateFilename, $sshPair['private']);

        return new Certificate($certificateFilename, $sshPair['private']);
    }

    /**
     * Checks whether a valid certificate exists with other necessary files.
     *
     * @return Certificate|false
     */
    public function getExistingCertificate()
    {
        $dir = $this->getCertificateDir();
        $private = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $cert = $private . '-cert.pub';

        $exists = is_dir($dir) && file_exists($private) && file_exists($cert);
        if (!$exists) {
            return false;
        }

        return new Certificate($cert, $private);
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
        $includeFilename = $this->getCertificateDir() . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;

        $suggestedConfig = 'Host ' . $this->config->get('api.ssh_domain_wildcard') . PHP_EOL
            . '  Include ' . $includeFilename;

        $manualMessage = 'To configure SSH manually, add the following lines to: <comment>' . $filename . '</comment>'
            . "\n" . $suggestedConfig;

        if (file_exists($filename)) {
            $currentContents = file_get_contents($filename);
            if ($currentContents === false) {
                $this->stdErr->writeln('Failed to read file: <comment>' . $filename . '</comment>');
                return false;
            }
            if (strpos($currentContents, $suggestedConfig) !== false) {
                $this->stdErr->writeln('The certificate is included in your SSH configuration: <info>' . $filename . '</info>');
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

        return true;
    }

    /**
     * Deletes certificate files and SSH configuration.
     */
    public function deleteConfiguration()
    {
        $dir = $this->getCertificateDir();
        if (is_dir($dir)) {
            $this->stdErr->writeln('Deleting SSH certificate and related files');
            $this->fs->remove($dir);
            $this->removeUserSshConfig();
        }
    }

    /**
     * Generate a temporary ssh key pair to request a new certificate.
     *
     * @param string $dir
     *   The certificate directory.
     * @param bool $recreate
     *   Whether to delete and recreate the keys if they already exist.
     *
     * @return array
     *   The paths to the private and public key (keyed by 'private' and 'public').
     */
    private function generateSshKey($dir, $recreate)
    {
        $sshInfo = [];
        $sshInfo['private'] = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $sshInfo['public'] = $sshInfo['private'] . '.pub';
        if (!$recreate && is_file($sshInfo['private']) && is_file($sshInfo['public'])) {
            $this->stdErr->writeln('Reusing local key pair', OutputInterface::VERBOSITY_VERBOSE);
            return $sshInfo;
        }
        $this->stdErr->writeln('Generating local key pair', OutputInterface::VERBOSITY_VERBOSE);
        // Delete the keys if they exist, to avoid interaction in the ssh-keygen command.
        if (file_exists($sshInfo['private']) || file_exists($sshInfo['public'])) {
            $this->fs->remove($sshInfo);
        }
        // Generate new keys and set permissions.
        $this->shell->execute(['ssh-keygen', '-t', 'rsa', '-N', '', '-f', $sshInfo['private']], null, true);
        @chmod($sshInfo['private'], 0600);
        @chmod($sshInfo['public'], 0600);

        return $sshInfo;
    }

    /**
     * Returns the directory containing certificate files.
     *
     * @return string
     */
    private function getCertificateDir()
    {
        return $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
    }

    /**
     * Generates a short lived SSH certificate for the user identified by the provided oauth token,
     * based on a provided SSH public key, and signed by one of the SSH authority keys.
     *
     * @param string the ssh public key.
     *
     * @return string the certificate.
     */
    private function requestCertificate($sshKey)
    {
        // Generate a request object using the access token.
        // @todo move this request into the PHP client
        $httpClient = $this->api->getClient()->getConnector()->getClient();
        $certificate = $httpClient->post($this->config->get('api.certifier_url') . '/ssh', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'key' => $sshKey,
            ],
        ])->json();

        if (empty($certificate)) {
            throw new \RuntimeException('Failed to refresh the certificate.');
        }

        return $certificate['certificate'];
    }

    /**
     * Creates an SSH config file, which sets and auto-refreshes the certificate.
     *
     * @param string $certificateFilename
     * @param string $privateKeyFilename
     */
    private function createSshConfig($certificateFilename, $privateKeyFilename)
    {
        $filename = $this->getCertificateDir() . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;
        $executable = $this->config->get('application.executable');
        $refreshCommand = sprintf('%s ssh-cert:load --refresh-only --yes --quiet', $executable);
        $lines = [
            sprintf('Match host %s exec "%s"', $this->config->get('api.ssh_domain_wildcard'), $refreshCommand),
            sprintf('  CertificateFile %s', $certificateFilename),
            sprintf('  IdentityFile %s', $privateKeyFilename),
        ];

        $this->fs->writeFile($filename, implode(PHP_EOL, $lines) . PHP_EOL, false);
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
     * Returns the path to the user's User SSH config file.
     *
     * @return string
     */
    private function getUserSshConfigFilename()
    {
        return $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * Removes certificate configuration from the user's global SSH config file.
     */
    private function removeUserSshConfig()
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
