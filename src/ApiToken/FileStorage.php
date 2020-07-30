<?php

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\Service\Config;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Stores API tokens using a hidden file.
 */
class FileStorage implements StorageInterface {
    private $config;
    private $fs;

    public function __construct(Config $config, SymfonyFilesystem $fs = null)
    {
        $this->config = $config;
        $this->fs = $fs ?: new SymfonyFilesystem();
    }

    /**
     * Loads the API token.
     *
     * @return string
     */
    public function getToken() {
        return $this->load();
    }

    /**
     * Stores an API token.
     *
     * @param string $value
     */
    public function storeToken($value) {
        $this->save($value);
    }

    /**
     * Deletes the saved token.
     */
    public function deleteToken() {
        $this->save('');
    }

    /**
     * @param string $token
     */
    private function save($token) {
        $filename = $this->getFilename();
        if (empty($token)) {
            if (file_exists($filename)) {
                $this->fs->remove($filename);
            }
            return;
        }

        // Avoid overwriting an already configured token file.
        if (file_exists($filename) && $this->config->has('api.token_file') && $this->resolveTokenFile($this->config->get('api.token_file')) === $filename) {
            throw new \RuntimeException('Failed to save API token: it would conflict with the existing api.token_file configuration.');
        }

        $this->fs->dumpFile($filename, $token);
        $this->fs->chmod($filename, 0600);
    }

    /**
     * @return string
     */
    private function load() {
        $filename = $this->getFilename();
        if (file_exists($filename)) {
            return trim((string) file_get_contents($filename));
        }

        return '';
    }

    /**
     * @return string
     */
    private function getFilename() {
        return $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'api-token';
    }

    /**
     * Makes a relative path absolute, based on the user config dir.
     *
     * @param string $tokenFile
     *
     * @return string
     */
    private function resolveTokenFile($tokenFile) {
        if (strpos($tokenFile, '/') !== 0 && strpos($tokenFile, '\\') !== 0) {
            $tokenFile = $this->config->getUserConfigDir() . '/' . $tokenFile;
        }

        return $tokenFile;
    }
}
