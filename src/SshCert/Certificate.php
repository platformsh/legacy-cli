<?php

namespace Platformsh\Cli\SshCert;

class Certificate {
    private $certFile;
    private $privateKeyFile;
    private $metadata;

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
     * @throws \RuntimeException if the certificate file cannot be read
     *
     * @return Metadata
     */
    public function metadata()
    {
        if (isset($this->metadata)) {
            return $this->metadata;
        }
        $contents = \file_get_contents($this->certFile);
        if (!$contents) {
            throw new \RuntimeException('Failed to read certificate file: ' . $this->certFile);
        }
        return $this->metadata = new Metadata($contents);
    }

    /**
     * Checks if the certificate has expired.
     *
     * @param int $buffer
     *   A duration in seconds by which to reduce the certificate's lifetime,
     *   to account for clock drift. Defaults to 300 (five minutes).
     *
     * @return bool
     */
    public function hasExpired($buffer = 300) {
        return $this->metadata()->getValidBefore() - $buffer < \time();
    }

    /**
     * Checks the certificate's "has MFA" claim: whether the user was authenticated via MFA.
     *
     * @return bool
     */
    public function hasMfa() {
        return \array_key_exists('has-mfa@platform.sh', $this->metadata()->getExtensions());
    }

    /**
     * Checks the certificate's "is app" claim: whether the authentication mode is non-interactive.
     *
     * @return bool
     */
    public function isApp() {
        return \array_key_exists('is-app@platform.sh', $this->metadata()->getExtensions());
    }
}
