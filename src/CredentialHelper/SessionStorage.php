<?php

declare(strict_types=1);

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Client\Session\Storage\SessionStorageInterface;

/**
 * Stores sessions using the Credential Helper.
 */
readonly class SessionStorage implements SessionStorageInterface
{
    private string $serverUrlBase;

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
    public function __construct(private Manager $manager, string $serverUrlPrefix)
    {
        $this->serverUrlBase = rtrim($serverUrlPrefix, '/');
    }

    /**
     * @param string $sessionId
     *
     * @return string
     */
    private function serverUrl(string $sessionId): string
    {
        // Remove the 'cli-' prefix from the session ID;
        if (str_starts_with($sessionId, 'cli-')) {
            $sessionId = substr($sessionId, 4);
        }

        return $this->serverUrlBase . '/' . $sessionId;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $sessionId): array
    {
        try {
            $secret = $this->manager->get($this->serverUrl($sessionId));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to load the session', 0, $e);
        }

        return $secret ? $this->deserialize($secret) : [];
    }

    /**
     * Checks if any sessions we own exist in the credential store.
     *
     * @return bool
     */
    public function hasAnySessions(): bool
    {
        return count($this->listAllServerUrls()) > 0;
    }

    /**
     * @return string[]
     */
    public function listSessionIds(): array
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
    public function deleteAll(): void
    {
        foreach ($this->listAllServerUrls() as $url) {
            $this->manager->erase($url);
        }
    }

    /**
     * @return string[]
     */
    private function listAllServerUrls(): array
    {
        $list = $this->manager->listAll();

        return array_filter(array_keys($list), fn($url): bool => str_starts_with((string) $url, $this->serverUrlBase . '/'));
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $sessionId, array $data): void
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
            $this->manager->store($serverUrl, $this->serialize($data));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Failed to store the session', 0, $e);
        }
    }

    /**
     * Serialize session data.
     *
     * @param array<string, mixed> $data
     *
     * @return string
     */
    private function serialize(array $data): string
    {
        return base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deserialize session data.
     *
     * @param string $data
     *
     * @return array<string, mixed>
     */
    private function deserialize(string $data): array
    {
        $result = json_decode((string) base64_decode($data, true), true);

        return is_array($result) ? $result : [];
    }
}
