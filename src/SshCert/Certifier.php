<?php

namespace Platformsh\Cli\SshCert;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Certifier
{
    const KEY_ALGORITHM = 'ed25519';
    const PRIVATE_KEY_FILENAME = 'id_ed25519';

    private $api;
    private $config;
    private $shell;
    private $fs;
    private $stdErr;

    public function __construct(Api $api, Config $config, Shell $shell, Filesystem $fs, OutputInterface $output)
    {
        $this->api = $api;
        $this->config = $config;
        $this->shell = $shell;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
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
     * @return Certificate
     */
    public function generateCertificate()
    {
        $dir = $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
        $this->fs->mkdir($dir, 0700);

        // Remove the old certificate and key from the SSH agent.
        $this->shell->execute(['ssh-add', '-d', $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME], null, false, !$this->stdErr->isVeryVerbose());

        // Ensure the user is logged in to the API, so that an auto-login will
        // not be triggered after we have generated keys (auto-login triggers a
        // logout, which wipes keys).
        $apiClient = $this->api->getClient();

        $sshPair = $this->generateSshKey($dir, true);
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
        $certificate = $apiClient->getSshCertificate($publicContents);

        $this->fs->writeFile($certificateFilename, $certificate);
        $this->chmod($certificateFilename, 0600);

        $certificate = new Certificate($certificateFilename, $sshPair['private']);

        // Add the key to the SSH agent, if possible, silently.
        // In verbose mode the full command will be printed, so the user can
        // re-run it to check error details.
        $lifetime = ($certificate->metadata()->getValidBefore() - time()) ?: 3600;
        $this->shell->execute(['ssh-add', '-t', $lifetime, $sshPair['private']], null, false, !$this->stdErr->isVerbose());

        return $certificate;
    }

    /**
     * Checks whether a valid certificate exists with other necessary files.
     *
     * @return Certificate|null
     */
    public function getExistingCertificate()
    {
        $dir = $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
        $private = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $cert = $private . '-cert.pub';

        $exists = file_exists($private) && file_exists($cert);

        return $exists ? new Certificate($cert, $private) : null;
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
        $args = [
            'ssh-keygen',
            '-t', self::KEY_ALGORITHM,
            '-f', $sshInfo['private'],
            '-N', '', // No passphrase
            '-C', $this->config->get('application.slug') . '-temporary-cert', // Key comment
        ];
        $this->shell->execute($args, null, true);
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
