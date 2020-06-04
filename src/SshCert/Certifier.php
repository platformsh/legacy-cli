<?php

namespace Platformsh\Cli\SshCert;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SshConfig;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Certifier
{
    const PRIVATE_KEY_FILENAME = 'id_rsa';

    private $api;
    private $config;
    private $shell;
    private $fs;
    private $stdErr;
    private $sshConfig;

    public function __construct(Api $api, Config $config, Shell $shell, Filesystem $fs, OutputInterface $output, SshConfig $sshConfig)
    {
        $this->api = $api;
        $this->config = $config;
        $this->shell = $shell;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshConfig = $sshConfig;
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
        $dir = $this->sshConfig->getSessionSshDir();
        $this->fs->mkdir($dir, 0700);

        $sshPair = $this->generateSshKey($dir, $newKeyPair);
        $publicContents = file_get_contents($sshPair['public']);
        if (!$publicContents) {
            throw new \RuntimeException('Failed to read public key file: ' . $publicContents);
        }

        $certificateFilename = $sshPair['private'] . '-cert.pub';
        // Remove the existing certificate before generating a new one, so as
        // not to leave an invalid key/cert set.
        if (\file_exists($certificateFilename)) {
            $this->fs->remove($certificateFilename);
        }

        $this->stdErr->writeln('Requesting certificate from the API', OutputInterface::VERBOSITY_VERBOSE);
        $certificate = $this->api->getClient()->getSshCertificate($publicContents);

        $this->fs->writeFile($certificateFilename, $certificate);
        $this->chmod($certificateFilename, 0600);

        $certificate = new Certificate($certificateFilename, $sshPair['private']);

        $this->sshConfig->configureSessionSsh($certificate);

        return $certificate;
    }

    /**
     * Checks whether a valid certificate exists with other necessary files.
     *
     * @return Certificate|null
     */
    public function getExistingCertificate()
    {
        $dir = $this->sshConfig->getSessionSshDir();
        $private = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $cert = $private . '-cert.pub';

        $exists = file_exists($private) && file_exists($cert);

        return $exists ? new Certificate($cert, $private) : null;
    }

    /**
     * Deletes certificate and SSH configuration files.
     */
    public function deleteFiles()
    {
        $dir = $this->sshConfig->getSessionSshDir();
        $private = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $public = $private . '.pub';
        $cert = $private . '-cert.pub';

        if (\file_exists($private) || \file_exists($cert) || \file_exists($public)) {
            $this->stdErr->writeln('Deleting SSH certificate and related files');
            $this->fs->remove([$private, $cert, $public]);
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
        $this->chmod($sshInfo['private'], 0600);
        $this->chmod($sshInfo['public'], 0600);

        return $sshInfo;
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
}
