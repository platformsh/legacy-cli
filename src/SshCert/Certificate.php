<?php

namespace Platformsh\Cli\SshCert;

use Platformsh\Client\SshCert\Metadata;

class Certificate {
    private $certFile;
    private $privateKeyFile;
    private $metadata;
    private $contents;

    /**
     * Certificate constructor.
     *
     * @param string $certFile
     * @param string $privateKeyFile
     */
    public function __construct($certFile, $privateKeyFile)
    {
        $this->certFile = $certFile;
        $this->privateKeyFile = $privateKeyFile;
        $this->contents = \file_get_contents($this->certFile);
        if (!$this->contents) {
            throw new \RuntimeException('Failed to read certificate file: ' . $this->certFile);
        }
        $this->metadata = new Metadata($this->contents);
    }

    /**
     * Returns if two certificates are identical.
     *
     * @param Certificate $cert
     *
     * @return bool
     */
    public function isIdentical(Certificate $cert)
    {
        return $cert->contents === $this->contents;
    }

    /**
     * @return string
     */
    public function certificateFilename()
    {
        return $this->certFile;
    }

    /**
     * @return string
     */
    public function privateKeyFilename()
    {
        return $this->privateKeyFile;
    }

    /**
     * Returns certificate metadata.
     *
     * @return Metadata
     */
    public function metadata()
    {
        return $this->metadata;
    }

    /**
     * Checks if the certificate has expired.
     *
     * @param int $buffer
     *   A duration in seconds by which to reduce the certificate's lifetime,
     *   to account for clock drift. Defaults to 120 (two minutes).
     *
     * @return bool
     */
    public function hasExpired($buffer = 120) {
        return $this->metadata->getValidBefore() - $buffer < \time();
    }

    /**
     * Checks the certificate's "has MFA" claim: whether the user was authenticated via MFA.
     *
     * @return bool
     */
    public function hasMfa() {
        return \array_key_exists('has-mfa@platform.sh', $this->metadata->getExtensions());
    }

    /**
     * Checks the certificate's "is app" claim: whether the authentication mode is non-interactive.
     *
     * @return bool
     */
    public function isApp() {
        return \array_key_exists('is-app@platform.sh', $this->metadata->getExtensions());
    }
}
