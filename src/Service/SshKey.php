<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\SshKey as SshKeyModel;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SSH key utilities.
 */
readonly class SshKey
{
    private OutputInterface $stdErr;

    public function __construct(private Config $config, private Api $api, OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Returns a list of default SSH identity basenames.
     *
     * These are the base filenames that are used by SSH when there is no
     * IdentityFile specified, and when there is no SSH agent.
     *
     * @see https://man.openbsd.org/ssh#i
     *
     * @return string[]
     */
    public function defaultKeyNames(): array
    {
        return [
            'id_rsa',
            'id_ecdsa_sk',
            'id_ecdsa',
            'id_ed25519_sk',
            'id_ed25519',
            'id_dsa',
        ];
    }

    /**
     * Returns a local SSH identity that matches the user's current account.
     *
     * This can be configured as the IdentityFile for SSH.
     *
     * Use cases: (1) if the user has multiple keys, for multiple Platform.sh
     * accounts, and (2) if the user has only one key, but it is in a
     * non-standard location.
     *
     * @param bool $reset
     *
     * @return string|null
     *   An absolute filename of an SSH private key, or null if there is no
     *   selected key.
     */
    public function selectIdentity(bool $reset = false): ?string
    {
        // Cache, mainly to avoid repetition of the output message.
        static $selectedIdentity = false;
        if (!$reset && $selectedIdentity !== false) {
            return $selectedIdentity;
        }
        $selectedIdentity = null;

        if (!$this->api->isLoggedIn()) {
            return null;
        }

        $accountKeyFingerprints = $this->listAccountKeyFingerprints();
        if (!$accountKeyFingerprints) {
            return null;
        }

        // Do not return a specific key if there is only one that will likely
        // be used by default.
        $publicKeys = $this->listPublicKeys(true);
        if (\count($publicKeys) === 1) {
            $filename = \reset($publicKeys) ?: '';
            $identityFile = \substr((string) $filename, 0, \strlen((string) $filename) - 4);
            if (\in_array(\basename($identityFile), $this->defaultKeyNames(), true)) {
                return null;
            }
        }

        if ($key = $this->findIdentityMatchingPublicKeys($accountKeyFingerprints)) {
            $this->stdErr->writeln(sprintf('Automatically selected SSH identity: <info>%s</info>', $key), OutputInterface::VERBOSITY_VERBOSE);
            return $selectedIdentity = $key;
        }

        return null;
    }

    /**
     * Lists existing public key files.
     *
     * @return string[]
     */
    private function listPublicKeys(bool $reset = false): array
    {
        static $publicKeyList;
        if (!isset($publicKeyList) || $reset) {
            $publicKeyList = \glob($this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh' . DIRECTORY_SEPARATOR . '*.pub') ?: [];
        }
        return $publicKeyList;
    }

    /**
     * Lists SSH key MD5 fingerprints in the user's account.
     *
     * @return string[]
     */
    private function listAccountKeyFingerprints(): array
    {
        $keys = $this->api->getSshKeys();
        if (!count($keys)) {
            return [];
        }

        return \array_map(fn(SshKeyModel $sshKey) => $sshKey->fingerprint, $keys);
    }

    /**
     * Checks whether the user has an SSH key in ~/.ssh matching their account.
     */
    public function hasLocalKey(): bool
    {
        return $this->findIdentityMatchingPublicKeys($this->listAccountKeyFingerprints()) !== null;
    }

    /**
     * @param string[] $fingerprints
     *
     * @return string|null
     *   The filename of the key, or null if none is found.
     */
    public function findIdentityMatchingPublicKeys(array $fingerprints): ?string
    {
        foreach ($this->listPublicKeys() as $publicKey) {
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
            if (\in_array($fingerprint, $fingerprints, true)) {
                return $privateKey;
            }
        }
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
    public function getPublicKeyFingerprint(string $filename): string
    {
        $contents = \file_get_contents($filename);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }
        if (!str_contains($contents, ' ')) {
            throw new \RuntimeException('Invalid public key: ' . $filename);
        }
        [, $keyB64] = \explode(' ', $contents, 3);
        $key = \base64_decode($keyB64, true);
        if ($key === false) {
            throw new \RuntimeException('Failed to base64-decode public key: ' . $filename);
        }

        return \md5($key);
    }
}
