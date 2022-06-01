<?php

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\Storage\SessionStorageInterface;

/**
 * Stores sessions using the Credential Helper.
 */
class SessionStorage implements SessionStorageInterface
{
    private $serverUrlBase;
    private $manager;
    private $config;

    /**
     * CredentialsHelperStorage constructor.
     *
     * @param Manager $manager
     * @param string $serverUrlPrefix
     *   A unique "server URL" that identifies this application. Note, it does
     *   not actually have to be a URL at this time, and a human-readable value
     *   is more helpful to the user. In Windows this will be described as the
     *   "Internet or network address".
     * @param Config|null $config
     */
    public function __construct(Manager $manager, $serverUrlPrefix, Config $config = null)
    {
        $this->manager = $manager;
        $this->serverUrlBase = rtrim($serverUrlPrefix, '/');
        $this->config = $config ?: new Config();
    }

    private function serverUrl(string $sessionId): string {
        // Remove the 'cli-' prefix from the session ID;
        if (strpos($sessionId, 'cli-') === 0) {
            $sessionId = substr($sessionId, 4);
        }

        return $this->serverUrlBase . '/' . $sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function load($sessionId)
    {
        try {
            $secret = $this->manager->get($this->serverUrl($sessionId));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to load the session', 0, $e);
        }

        if ($secret !== false) {
            return $this->deserialize($secret);
        }

        // If data doesn't exist in the credential store yet, load it from
        // an old file for backwards compatibility.
        return $this->loadFromFile($sessionId);
    }

    /**
     * Checks if any sessions we own exist in the credential store.
     *
     * @return bool
     */
    public function hasAnySessions() {
        return count($this->listAllServerUrls()) > 0;
    }

    /**
     * @return array
     */
    public function listSessionIds()
    {
        $ids = [];
        foreach ($this->listAllServerUrls() as $url) {
            $path = basename($url);
            if ($path !== 'api-token') {
                $ids[] = $path;
            }
        }
        return $ids;
    }

    /**
     * Deletes all sessions from the credential store.
     */
    public function deleteAll() {
        foreach ($this->listAllServerUrls() as $url) {
            $this->manager->erase($url);
        }
    }

    /**
     * @return string[]
     */
    private function listAllServerUrls() {
        $list = $this->manager->listAll();

        return array_filter(array_keys($list), function ($url) {
            return strpos($url, $this->serverUrlBase) === 0;
        });
    }

    /**
     * Load the session from an old file for backwards compatibility.
     */
    private function loadFromFile(string $sessionId)
    {
        $id = preg_replace('/[^\w\-]+/', '-', $sessionId);
        $dir = $this->config->getSessionDir();
        $filename = "$dir/sess-$id/sess-$id.json";
        if (is_readable($filename) && ($contents = file_get_contents($filename))) {
            rename($filename, $filename . '.bak');
            return json_decode($contents, true) ?: [];
        }
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function save($sessionId, array $data)
    {
        $serverUrl = $this->serverUrl($sessionId);
        if (empty($data)) {
            try {
                $this->manager->erase($serverUrl);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Failed to erase the session', 0, $e);
            }
            return;
        }
        try {
            $this->manager->store($this->serverUrl($sessionId), $this->serialize($data));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to store the session', 0, $e);
        }
    }

    /**
     * Serialize session data.
     *
     * @param array $data
     *
     * @return string
     */
    private function serialize(array $data)
    {
        return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deserialize session data.
     *
     * @param string $data
     *
     * @return array
     */
    private function deserialize($data)
    {
        $result = json_decode(base64_decode($data, true), true);

        return is_array($result) ? $result : [];
    }
}
