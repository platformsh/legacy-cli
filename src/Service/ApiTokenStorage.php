<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Stores and retrieves an API token.
 */
class ApiTokenStorage
{
    private $config;
    private $data = [];
    private $loaded = false;

    /**
     * @param Config $config
     */
    public function __construct(Config $config) {
        $this->config = $config;
    }

    /**
     * Checks if an API token is saved.
     *
     * @return bool
     */
    public function hasToken() {
        $this->load();

        return isset($this->data['apiToken']);
    }

    /**
     * Loads the API token.
     *
     * @return string
     */
    public function getToken() {
        $this->load();
        if (!isset($this->data['apiToken'])) {
            throw new \RuntimeException('No API token found');
        }

        return $this->data['apiToken'];
    }

    /**
     * Stores an API token.
     *
     * @param string $value
     */
    public function storeToken($value) {
        $this->load();
        $this->data['apiToken'] = $value;
        $this->save();
    }

    /**
     * Deletes the saved token.
     */
    public function deleteToken() {
        unset($this->data['apiToken']);
        $this->save();
    }

    /**
     * Saves data.
     */
    private function save() {
        $filename = $this->getFilename();
        $fs = new SymfonyFilesystem();
        if (empty($this->data)) {
            if (file_exists($filename)) {
                $fs->remove($filename);
            }
            return;
        }
        $fs->dumpFile($filename, $this->data['apiToken']);
    }

    /**
     * Loads stored data.
     */
    private function load() {
        if (!$this->loaded) {
            $filename = $this->getFilename();
            if (file_exists($filename)) {
                $this->data['apiToken'] = trim((string) file_get_contents($filename));
            }
            $this->loaded = true;
        }
    }

    /**
     * @return string
     */
    private function getFilename() {
        return $this->config->getUserConfigDir() . '/' . $this->config->getWithDefault('application.api_token_file', '.api-token');
    }
}
