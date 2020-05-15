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
     * @return Metadata
     */
    public function metadata()
    {
        if (isset($this->metadata)) {
            return $this->metadata;
        }
        $contents = file_get_contents($this->certFile);
        if (!$contents) {
            throw new \RuntimeException('Failed to read certificate file: ' . $this->certFile);
        }
        return $this->metadata = new Metadata($contents);
    }
}
