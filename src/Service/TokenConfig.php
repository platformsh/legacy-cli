<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\ApiToken\Storage;

class TokenConfig
{
    private $config;
    private $apiTokenStorage;

    public function __construct(Config $config = null)
    {
        $this->config = $config ?: new Config();
        $this->apiTokenStorage = Storage::factory($this->config);
    }

    /**
     * @return \Platformsh\Cli\ApiToken\StorageInterface
     */
    public function storage()
    {
        return $this->apiTokenStorage;
    }

    /**
     * @param bool $includeStored
     *
     * @return string|null
     */
    public function getApiToken($includeStored = true)
    {
        if ($includeStored) {
            $storedToken = $this->apiTokenStorage->getToken();
            if ($storedToken !== '') {
                return $storedToken;
            }
        }

        $token = (string) $this->config->getWithDefault('api.token', '');
        if ($token !== '') {
            return $token;
        }

        $file = (string) $this->config->getWithDefault('api.token_file', '');
        if ($file !== '') {
            return $this->loadTokenFromFile($file);
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getAccessToken()
    {
        return $this->config->getWithDefault('api.access_token', null);
    }

    /**
     * Load an API token from a file.
     *
     * @param string $filename
     *   A filename, either relative to the user config directory, or absolute.
     *
     * @return string
     */
    private function loadTokenFromFile($filename)
    {
        if (strpos($filename, '/') !== 0 && strpos($filename, '\\') !== 0) {
            $filename = $this->config->getUserConfigDir() . '/' . $filename;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }

        return trim($content);
    }
}
