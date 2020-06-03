<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Selects a local SSH key based on the public key(s) added to an account.
 */
class KeySelector {
    private $config;
    private $api;
    private $stdErr;

    public function __construct(Config $config, Api $api, OutputInterface $output) {
        $this->config = $config;
        $this->api = $api;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Returns a local SSH private key that matches the user's current account.
     *
     * @return string|null
     *   An absolute filename of an SSH key, or null if there is no selected key.
     */
    public function getIdentityFile() {
        $accountInfo = $this->api->getMyAccount();
        if (!isset($accountInfo['ssh_keys'])) {
            return null;
        }
        $accountKeyFingerprints = \array_column($accountInfo['ssh_keys'], 'fingerprint');
        if (!\count($accountKeyFingerprints)) {
            return null;
        }
        $publicKeyList = \glob($this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . 'id_*.pub') ?: [];
        foreach ($publicKeyList as $publicKey) {
            $privateKey = \substr($publicKey, 0, \strlen($publicKey) - 4);
            if (!\file_exists($privateKey)) {
                continue;
            }
            try {
                $fingerprint = $this->getPublicKeyFingerprint($publicKey);
            } catch (\RuntimeException $e) {
                $this->stdErr->writeln('Failed to get SSH key fingerprint: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }
            if (\in_array($fingerprint, $accountKeyFingerprints, true)) {
                $this->stdErr->writeln(sprintf('Automatically selected SSH identity: <info>%s</info>', $publicKey), OutputInterface::VERBOSITY_VERBOSE);
                return $privateKey;
            }
        }

        $this->stdErr->writeln('Failed to select an SSH identity automatically', OutputInterface::VERBOSITY_VERY_VERBOSE);

        return null;
    }

    /**
     * Returns an MD5 hash of a public key that matches its server fingerprint.
     *
     * @param string $filename An absolute path to the public key.
     *
     * @throws \RuntimeException if the fingerprint is not available (if the key is not readable or not valid)
     *
     * @return string
     */
    private function getPublicKeyFingerprint($filename) {
        $contents = \file_get_contents($filename);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }
        if (\strpos($contents, ' ') === false) {
            throw new \RuntimeException('Invalid public key: ' . $filename);
        }
        list(, $keyB64) = explode(' ', $contents, 3);
        $key = \base64_decode($keyB64, true);
        if ($key === false) {
            throw new \RuntimeException('Failed to base64-decode public key: ' . $filename);
        }

        return \md5($key);
    }
}
