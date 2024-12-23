<?php

declare(strict_types=1);

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
    public const KEY_ALGORITHM = 'ed25519';
    public const PRIVATE_KEY_FILENAME = 'id_ed25519';
    private readonly OutputInterface $stdErr;

    private static bool $disableAutoLoad = false;

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Shell $shell, private readonly Filesystem $fs, OutputInterface $output, private readonly FileLock $fileLock)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Returns whether automatic certificate loading (on login or SSH) is enabled.
     *
     * @return bool
     */
    public function isAutoLoadEnabled(): bool
    {
        return !self::$disableAutoLoad && $this->config->getBool('ssh.auto_load_cert');
    }

    /**
     * Generates a new certificate.
     */
    public function generateCertificate(?Certificate $previousCert, bool $forceNewKey = false): Certificate
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
        $result = $this->fileLock->acquireOrWait($lockName, function (): void {
            $this->stdErr->writeln('Waiting for SSH certificate generation lock', OutputInterface::VERBOSITY_VERBOSE);
        }, function () use ($previousCert) {
            // While waiting for the lock, check if a new certificate has
            // already been generated elsewhere.
            $newCert = $this->getExistingCertificate();
            return $newCert && (!$previousCert || !$previousCert->isIdentical($newCert)) ? $newCert : null;
        });
        if ($result !== null) {
            return $result;
        }

        try {
            return $this->doGenerateCertificate($forceNewKey);
        } finally {
            $this->fileLock->release($lockName);
        }
    }

    /**
     * Inner function to generate the actual certificate.
     *
     * @see self::generateCertificate()
     */
    private function doGenerateCertificate(bool $forceNewKey = false): Certificate
    {
        $dir = $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'ssh';
        $this->fs->mkdir($dir, 0o700);

        $privateKeyFilename = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILENAME;
        $certificateFilename = $privateKeyFilename . '-cert.pub';
        $publicKeyFilename = $privateKeyFilename . '.pub';
        $tempPrivateKeyFilename = $privateKeyFilename . '_tmp';
        $tempCertificateFilename = $tempPrivateKeyFilename . '-cert.pub';
        $tempPublicKeyFilename = $tempPrivateKeyFilename . '.pub';

        // Remove the old certificate and key from the SSH agent.
        if ($this->config->getBool('ssh.add_to_agent')) {
            $this->shell->execute(['ssh-add', '-d', $privateKeyFilename], null, false, !$this->stdErr->isVeryVerbose());
        }

        $apiClient = $this->api->getClient();

        $keyTtl = (int) $this->config->get('ssh.cert_key_ttl');
        $regenerateKey = $forceNewKey || !file_exists($privateKeyFilename) || !file_exists($publicKeyFilename)
            || ($keyTtl !== 0 && ($mtime = filemtime($privateKeyFilename)) && time() - $mtime > $keyTtl);

        if ($regenerateKey) {
            $this->generateSshKey($tempPrivateKeyFilename);

            $publicContents = file_get_contents($tempPublicKeyFilename);
            if (!$publicContents) {
                throw new \RuntimeException('Failed to read public key file: ' . $tempPublicKeyFilename);
            }
        } else {
            $publicContents = file_get_contents($publicKeyFilename);
            if (!$publicContents) {
                throw new \RuntimeException('Failed to read public key file: ' . $publicKeyFilename);
            }
        }

        $this->stdErr->writeln('Requesting certificate from the API', OutputInterface::VERBOSITY_VERBOSE);
        $certificate = $apiClient->getSshCertificate($publicContents);

        if (!file_put_contents($tempCertificateFilename, $certificate)) {
            throw new \RuntimeException('Failed to write file: ' . $tempCertificateFilename);
        }

        if (!chmod($tempCertificateFilename, 0o600)) {
            throw new \RuntimeException('Failed to change permissions on file: ' . $tempCertificateFilename);
        }

        // Rename the files as simultaneously as possible so they can replace
        // the existing certificate while causing minimal confusion to OpenSSH.
        // TODO is there really no way to make this atomic?
        $this->rename($tempCertificateFilename, $certificateFilename);
        if ($regenerateKey) {
            $this->rename($tempPrivateKeyFilename, $privateKeyFilename);
            $this->rename($tempPublicKeyFilename, $publicKeyFilename);
        }

        $certificate = new Certificate($certificateFilename, $privateKeyFilename);

        // Add the key to the SSH agent, if possible, silently.
        // In verbose mode the full command will be printed, so the user can
        // re-run it to check error details.
        if ($this->config->getBool('ssh.add_to_agent')) {
            $lifetime = ($certificate->metadata()->getValidBefore() - time()) ?: 3600;
            $this->shell->execute(['ssh-add', '-t', (string) $lifetime, $privateKeyFilename], null, false, !$this->stdErr->isVerbose());
        }

        return $certificate;
    }

    /**
     * Checks whether a certificate exists with other necessary files.
     *
     * @return Certificate|null
     */
    public function getExistingCertificate(): ?Certificate
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
    public function isValid(Certificate $certificate): bool
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
    public function certificateConflictsWithJwt(Certificate $certificate, ?string $jwt = null): bool
    {
        $extensions = $certificate->metadata()->getExtensions();
        if (!isset($extensions['access-id@platform.sh']) && !isset($extensions['access@platform.sh']) && !isset($extensions['token-claims@platform.sh'])) {
            // Only access-related claims matter.
            return false;
        }
        $jwt = $jwt ?: $this->api->getAccessToken();
        $claims = (new Jwt($jwt))->unsafeGetUnverifiedClaims();
        if (!$claims) {
            trigger_error('Unable to parse access token claims', E_USER_WARNING);
            return false;
        }

        // Check for a mismatch of access ID.
        if (isset($extensions['access-id@platform.sh']) && (!isset($claims['access_id']) || $claims['access_id'] !== $extensions['access-id@platform.sh'])) {
            return true;
        }

        // If the JWT contains any auth methods other than "pwd", check that
        // the SSH cert represents the same token. This may sometimes mean the
        // cert will be refreshed more often than necessary, but it will reduce
        // errors related to MFA and SSO enforcement.
        if (isset($claims['amr'], $claims['jti'], $extensions['token-id@platform.sh']) && $claims['amr'] !== ['pwd'] && $extensions['token-id@platform.sh'] !== $claims['jti']) {
            return true;
        }

        // Check for a mismatch of inline access document.
        $certAccess = $certificate->inlineAccess();
        if ($certAccess !== [] && (!isset($claims['access']) || $claims['access'] != $certAccess)) {
            return true;
        }

        // Check for a mismatch of any other token claims.
        $certTokenClaims = $certificate->tokenClaims();
        if ($certTokenClaims !== []) {
            foreach ($certTokenClaims as $key => $value) {
                if (!isset($claims[$key]) || $claims[$key] != $value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate an SSH key pair to request a new certificate.
     *
     * @param string $filename
     *   The private key filename.
     */
    private function generateSshKey(string $filename): void
    {
        $this->stdErr->writeln('Generating local key pair', OutputInterface::VERBOSITY_VERBOSE);

        $args = [
            'ssh-keygen',
            '-t', self::KEY_ALGORITHM,
            '-f', $filename,
            '-N', '', // No passphrase
            '-C', $this->config->getStr('application.slug') . '-temporary-cert', // Key comment
        ];

        // The "y\n" input is passed to avoid an error or prompt if ssh-keygen
        // encounters existing keys. This seems to be necessary during race
        // conditions despite deleting keys in advance with $this->fs->remove().
        $this->fs->remove([$filename, $filename . '.pub']);
        $this->shell->mustExecute($args, timeout: 60, input: "y\n");
    }

    /**
     * Rename a file (allowing overwriting) and throw an exception on failure.
     *
     * @param string $source
     * @param string $target
     */
    private function rename(string $source, string $target): void
    {
        if (!\rename($source, $target)) {
            throw new \RuntimeException(sprintf('Failed to rename file from %s to %s', $source, $target));
        }
    }
}
