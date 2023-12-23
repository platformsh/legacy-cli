<?php

namespace Platformsh\Cli\SshCert;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\FileLock;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\Jwt;
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
    private $fileLock;

    private static $disableAutoLoad = false;

    public function __construct(Api $api, Config $config, Shell $shell, Filesystem $fs, OutputInterface $output, FileLock $fileLock)
    {
        $this->api = $api;
        $this->config = $config;
        $this->shell = $shell;
        $this->fs = $fs;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->fileLock = $fileLock;
    }

    /**
     * Returns whether automatic certificate loading (on login or SSH) is enabled.
     *
     * @return bool
     */
    public function isAutoLoadEnabled()
    {
        return !self::$disableAutoLoad && $this->config->getWithDefault('ssh.auto_load_cert', false);
    }

    /**
     * Whether to use the certificate only, if one is available.
     *
     * @return bool
     */
    public function useCertificateOnly()
    {
        return $this->config->get('ssh.cert_only');
    }

    /**
     * Generates a new certificate.
     *
     * @return Certificate
     */
    public function generateCertificate()
    {
        // Ensure the user is logged in to the API, so that an auto-login will
        // not be triggered after we have generated keys (auto-login triggers a
        // logout, which wipes keys).
        try {
            self::$disableAutoLoad = true;
            $this->api->getClient();
        } finally {
            self::$disableAutoLoad = false;
        }

        // Acquire a lock to prevent race conditions when certificate and key
        // files are changed at the same time in different CLI processes.
        $lockName = 'ssh-cert--' . $this->config->getSessionIdSlug();
        $start = time();
        $result = $this->fileLock->acquireOrWait($lockName, function () {
            $this->stdErr->writeln('Waiting for SSH certificate generation lock', OutputInterface::VERBOSITY_VERBOSE);
        }, function () use ($start) {
            // While waiting for the lock, check if a new certificate has
            // already been generated elsewhere.
            $cert = $this->getExistingCertificate();
            return $cert && $cert->metadata()->getValidAfter() >= $start && $this->isValid($cert)
                ? $cert : null;
        });
        if ($result !== null) {
            return $result;
        }

        try {
            return $this->doGenerateCertificate();
        } finally {
            $this->fileLock->release($lockName);
        }
    }

    /**
     * Inner function to generate the actual certificate.
     *
     * @see self::generateCertificate()
     *
     * @return Certificate
     */
    private function doGenerateCertificate()
    {
        $dir = $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
        $this->fs->mkdir($dir, 0700);

        // Remove the old certificate and key from the SSH agent.
        if ($this->config->getWithDefault('ssh.add_to_agent', false)) {
            $this->shell->execute(['ssh-add', '-d', $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME], null, false, !$this->stdErr->isVeryVerbose());
        }

        $apiClient = $this->api->getClient();

        $sshPair = $this->generateSshKey($dir, true);
        $publicContents = file_get_contents($sshPair['public']);
        if (!$publicContents) {
            throw new \RuntimeException('Failed to read public key file: ' . $sshPair['public']);
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
        if ($this->config->getWithDefault('ssh.add_to_agent', false)) {
            $lifetime = ($certificate->metadata()->getValidBefore() - time()) ?: 3600;
            $this->shell->execute(['ssh-add', '-t', $lifetime, $sshPair['private']], null, false, !$this->stdErr->isVerbose());
        }

        return $certificate;
    }

    /**
     * Checks whether a certificate exists with other necessary files.
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
     * Checks whether the certificate is valid.
     *
     * It must be not expired, and match the current user ID, and if the
     * certificate contains access claims, they must match the local JWT access
     * token (otherwise the certificate is likely to be rejected).
     *
     * @param Certificate $certificate
     * @return bool
     */
    public function isValid(Certificate $certificate)
    {
        if ($certificate->hasExpired()) {
            return false;
        }
        if ($certificate->metadata()->getKeyId() !== $this->api->getMyUserId()) {
            return false;
        }
        if ($this->certificateConflictsWithJwt($certificate)) {
            return false;
        }
        return true;
    }

    /**
     * Returns whether a certificate conflicts with the claims in a JWT.
     *
     * @param Certificate $certificate
     * @param string|null $jwt
     *   A JWT, or null to use the locally stored access token.
     *
     * @return bool
     */
    public function certificateConflictsWithJwt(Certificate $certificate, $jwt = null)
    {
        $extensions = $certificate->metadata()->getExtensions();
        if (!isset($extensions['access-id@platform.sh']) && !isset($extensions['access@platform.sh'])) {
            // Only access-related claims matter. The token ID is allowed to differ.
            return false;
        }
        $jwt = $jwt ?: $this->api->getAccessToken();
        $claims = (new Jwt($jwt))->unsafeGetUnverifiedClaims();
        if (!$claims) {
            trigger_error('Unable to parse access token claims', E_USER_WARNING);
            return false;
        }
        if (isset($extensions['access-id@platform.sh']) && (!isset($claims['access_id']) || $claims['access_id'] !== $extensions['access-id@platform.sh'])) {
            return true;
        }
        if (isset($extensions['access@platform.sh'])) {
            $certAccess = json_decode($extensions['access@platform.sh'], true);
            if (!isset($claims['access']) || $claims['access'] != $certAccess) {
                return true;
            }
        }
        return false;
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
        // Delete the keys if they exist.
        $this->fs->remove([$sshInfo['private'], $sshInfo['public']]);
        $this->stdErr->writeln('Generating local key pair', OutputInterface::VERBOSITY_VERBOSE);
        // Generate new keys and set permissions.
        $args = [
            'ssh-keygen',
            '-t', self::KEY_ALGORITHM,
            '-f', $sshInfo['private'],
            '-N', '', // No passphrase
            '-C', $this->config->get('application.slug') . '-temporary-cert', // Key comment
        ];
        // The "y\n" input is passed to avoid an error or prompt if ssh-keygen
        // encounters existing keys. This seems to be necessary during race
        // conditions despite having deleted the keys with $this->fs->remove()
        // above.
        $this->shell->execute($args, null, true, true, [], 60, "y\n");
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
