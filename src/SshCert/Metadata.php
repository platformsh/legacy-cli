<?php

namespace Platformsh\Cli\SshCert;

class Metadata {

    private $keyType;
    private $nonce;
    private $rsaExponent;
    private $publicModulus;
    private $serial;
    private $type;
    private $keyId;
    private $validPrincipals;
    private $validAfter;
    private $validBefore;
    private $criticalOptions;
    private $extensions;
    private $reserved;
    private $signatureKey;
    private $signature;

    /**
     * CertificateSsh constructor.
     *
     * Get all the keys from a RSA certificate.
     *
     * @see: https://cvsweb.openbsd.org/src/usr.bin/ssh/PROTOCOL.certkeys?annotate=HEAD
     *
     * @param string $string The certificate's contents.
     */
    public function __construct($string)
    {
        // Remove the key type i.e: ssh-rsa-cert-v01@openssh.com
        list($type, $cert) = \explode(' ', $string);
        if ($type !== 'ssh-rsa-cert-v01@openssh.com') {
            throw new \InvalidArgumentException('Unsupported key type: ' . $type);
        }
        $bytes = \base64_decode($cert, true);
        if (!$bytes) {
            throw new \InvalidArgumentException('Unable to decode SSH certificate');
        }
        $this->keyType = $this->readString($bytes);
        $this->nonce = $this->readString($bytes);
        $this->rsaExponent = $this->readString($bytes);
        $this->publicModulus = $this->readString($bytes);
        $this->serial = $this->readUint64($bytes);
        $this->type = $this->readUint32($bytes);
        $this->keyId = $this->readString($bytes);
        $this->validPrincipals = $this->readString($bytes);
        $this->validAfter = $this->readUint64($bytes);
        $this->validBefore = $this->readUint64($bytes);
        $this->criticalOptions = $this->readString($bytes);
        $this->extensions = $this->readString($bytes);
        $this->reserved = $this->readString($bytes);
        $this->signatureKey = $this->readString($bytes);
        $this->signature = $this->readString($bytes);
    }

    /**
     * Reads the next string, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return string
     */
    private function readString(&$bytes) {
        $len = \unpack('N', \substr($bytes, 0, 4));
        // The first unnamed element from \unpack() will be keyed by 1.
        $str = \substr($bytes, 4, $len[1] + 4 - 1);
        $bytes = \substr($bytes, 4 + $len[1]);
        return $str;
    }

    /**
     * Reads the next uint64, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return int
     */
    private function readUint64(&$bytes) {
        $ret = \unpack('J', \substr($bytes, 0, 8));
        $bytes = \substr($bytes, 8);
        return (int) $ret[1];
    }

    /**
     * Reads the next uint32, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return int
     */
    private function readUint32(&$bytes) {
        $ret = \unpack('N', \substr($bytes, 0, 4));
        $bytes = \substr($bytes, 4);
        return (int) $ret[1];
    }

    /**
     * Returns the expiry date of the certificate, as a UNIX timestamp.
     *
     * @return int
     */
    public function getValidBefore() {
        return $this->validBefore;
    }

    /**
     * Returns the certificate extensions (a string).
     *
     * @return string
     */
    public function extensions()
    {
        return $this->extensions;
    }
}
