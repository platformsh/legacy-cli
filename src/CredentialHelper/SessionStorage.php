<?php

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\SessionStorageInterface;

/**
 * Stores sessions using the Credential Helper.
 */
class SessionStorage implements SessionStorageInterface
{
    private $serverUrlBase;
    private $manager;

    /**
     * CredentialsHelperStorage constructor.
     *
     * @param Manager $manager
     * @param string $serverUrlPrefix
     *   A unique "server URL" that identifies this application. Note, it does
     *   not actually have to be a URL at this time, and a human-readable value
     *   is more helpful to the user. In Windows this will be described as the
     *   "Internet or network address".
     */
    public function __construct(Manager $manager, $serverUrlPrefix)
    {
        $this->manager = $manager;
        $this->serverUrlBase = rtrim($serverUrlPrefix, '/');
    }

    /**
     * @param SessionInterface $session
     *
     * @return string
     */
    private function serverUrl(SessionInterface $session) {
        // Remove the 'cli-' prefix from the session ID;
        $sessionId = $session->getId();
        if (strpos($sessionId, 'cli-') === 0) {
            $sessionId = substr($sessionId, 4);
        }

        return $this->serverUrlBase . '/' . $sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function load(SessionInterface $session)
    {
        try {
            $secret = $this->manager->get($this->serverUrl($session));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to load the session', 0, $e);
        }

        if ($secret !== false) {
            $session->setData($this->deserialize($secret));
        } else {
            // If data doesn't exist in the credential store yet, load it from
            // an old file for backwards compatibility.
            $this->loadFromFile($session);
        }
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
     *
     * @param \Platformsh\Client\Session\SessionInterface $session
     */
    private function loadFromFile(SessionInterface $session)
    {
        $id = preg_replace('/[^\w\-]+/', '-', $session->getId());
        $dir = (new Config())->getSessionDir(true);
        $filename = "$dir/sess-$id.json";
        if (is_readable($filename) && ($contents = file_get_contents($filename))) {
            $data = json_decode($contents, true) ?: [];
            $session->setData($data);
            $this->save($session);
            // Reload the session from the credential store, and delete the
            // file if successful.
            if (rename($filename, $filename . '.bak')) {
                $this->load($session);
                if ($session->getData()) {
                    unlink($filename . '.bak');
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(SessionInterface $session)
    {
        $serverUrl = $this->serverUrl($session);
        $data = $session->getData();
        if (empty($data)) {
            try {
                $this->manager->erase($serverUrl);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Failed to erase the session', 0, $e);
            }
            return;
        }
        try {
            $this->manager->store($this->serverUrl($session), $this->serialize($data));
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
