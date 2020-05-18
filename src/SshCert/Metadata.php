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
        list(, $cert) = explode(' ', $string);

        $bytes = base64_decode($cert);
        // key type
        $this->keyType = $this->readString($bytes);
        // nonce
        $this->nonce = $this->readString($bytes);
        // exponent
        $this->rsaExponent = $this->readString($bytes);
        // public modulus
        $this->publicModulus = $this->readString($bytes);
        // serial
        $this->serial = $this->readUint64($bytes);
        // type
        $this->type = $this->readUint32($bytes);
        // key id
        $this->keyId = $this->readString($bytes);
        // valid principals
        $this->validPrincipals = $this->readString($bytes);
        // valid after
        $this->validAfter = $this->readUint64($bytes);
        // valid before
        $this->validBefore = $this->readUint64($bytes);
        // critical options
        $this->criticalOptions = $this->readString($bytes);
        // extensions
        $this->extensions = $this->readString($bytes);
        // reserved
        $this->reserved = $this->readString($bytes);
        // signature key
        $this->signatureKey = $this->readString($bytes);
        // signature
        $this->signature = $this->readString($bytes);
    }

    /**
     * Reads the next string, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return string
     */
    private function readString(&$bytes) {
        $len = unpack('N', substr($bytes, 0, 4));
        $str = substr($bytes, 4, $len[1] + 4 - 1);
        $bytes = substr($bytes, 4 + $len[1]);
        return $str;
    }

    /**
     * Reads the next uint64, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return int
     */
    private function readUint64(&$bytes) {
        $ret = unpack('J', substr($bytes, 0, 8));
        $bytes = substr($bytes, 8);
        return (int) $ret[1];
    }

    /**
     * Reads the next uint32, and removes it from the remaining bytes.
     *
     * @param string $bytes
     * @return int
     */
    private function readUint32(&$bytes) {
        $ret = unpack('N', substr($bytes, 0, 4));
        $bytes = substr($bytes, 4);
        return (int) $ret[1];
    }

    /**
     * Gets the UNIX timestamp after which the certificate is considered expired.
     * after which the certificate is considered expired.
     *
     * @return int
     */
    public function validBefore() {
        return $this->validBefore;
    }

    /**
     * Checks if the certificate has expired.
     *
     * @param float $buffer
     *   A proportion by which to reduce the certificate's lifetime, to provide
     *   a buffer. Defaults to 20%.
     *
     * @return bool
     */
    public function hasExpired($buffer = .2) {
        $buffer = ($this->validBefore - $this->validAfter) * $buffer;

        return $this->validBefore - $buffer < time();
    }
}
