<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class SslUtil
{
    /**
     * Reads and validates certificate and key paths.
     *
     * Strict validation is only performed if the openssl extension is installed.
     * If it is not installed, validation can be performed remotely by the API.
     *
     * @param string $certPath
     * @param string $keyPath
     * @param string[] $chainPaths
     *
     * @throws \InvalidArgumentException
     *
     * @return array{certificate: string, key: string, chain: string[]}
     *   An array containing the contents of the certificate files.
     */
    public function validate(string $certPath, string $keyPath, array $chainPaths): array
    {
        // Get the contents.
        if (!is_readable($certPath)) {
            throw new \InvalidArgumentException('The certificate file could not be read: ' . $certPath);
        }
        if (!is_readable($keyPath)) {
            throw new \InvalidArgumentException('The private key file could not be read: ' . $keyPath);
        }
        $sslCert = trim((string) file_get_contents($certPath));
        $sslPrivateKey = trim((string) file_get_contents($keyPath));

        // Validate the certificate and the key together, if openssl is enabled.
        if (\extension_loaded('openssl')) {
            $certResource = @openssl_x509_read($sslCert);
            if (!$certResource) {
                throw new \InvalidArgumentException('The certificate file is not a valid X509 certificate: ' . $certPath);
            }
            $keyResource = openssl_pkey_get_private($sslPrivateKey);
            if (!$keyResource) {
                throw new \InvalidArgumentException('Private key not valid, or passphrase-protected: ' . $keyPath);
            }
            $keyMatch = openssl_x509_check_private_key($certResource, $keyResource);
            if (!$keyMatch) {
                throw new \InvalidArgumentException('The provided certificate does not match the provided private key.');
            }
        }

        $chain = $this->validateChain($chainPaths);

        return [
            'certificate' => $sslCert,
            'key' => $sslPrivateKey,
            'chain' => $chain,
        ];
    }

    /**
     * Splits up and validates chain file contents.
     *
     * @param string[] $chainPaths
     *
     * @return string[]
     *   Each certificate in the chain.
     */
    public function validateChain(array $chainPaths): array
    {
        $chain = [];
        foreach ($chainPaths as $chainPath) {
            if (!is_readable($chainPath)) {
                throw new \InvalidArgumentException("The chain file could not be read: $chainPath");
            }
            $data = trim((string) file_get_contents($chainPath));
            if (\preg_match_all('/--+BEGIN CERTIFICATE--+.+?--+END CERTIFICATE--+/is', $data, $matches) === false) {
                throw new \InvalidArgumentException("The chain file is not a valid list of X509 certificates: $chainPath");
            }
            if (\extension_loaded('openssl')) {
                foreach ($matches[0] as $chainCert) {
                    if (!openssl_x509_read($chainCert)) {
                        throw new \InvalidArgumentException("The chain file contains an invalid X509 certificate: $chainPath");
                    }
                }
            }
            $chain = \array_merge($chain, $matches[0]);
        }
        return $chain;
    }
}
