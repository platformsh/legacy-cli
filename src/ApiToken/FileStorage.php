<?php

declare(strict_types=1);

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\Service\Config;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Stores API tokens using a hidden file.
 */
readonly class FileStorage implements StorageInterface
{
    private SymfonyFilesystem $fs;

    public function __construct(private Config $config, ?SymfonyFilesystem $fs = null)
    {
        $this->fs = $fs ?: new SymfonyFilesystem();
    }

    /**
     * Loads the API token.
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->load();
    }

    /**
     * Stores an API token.
     *
     * @param string $value
     */
    public function storeToken(string $value): void
    {
        $this->save($value);
    }

    /**
     * Deletes the saved token.
     */
    public function deleteToken(): void
    {
        $this->save('');
    }

    private function save(string $token): void
    {
        $filename = $this->getFilename();
        if (empty($token)) {
            if (file_exists($filename)) {
                $this->fs->remove($filename);
            }
            return;
        }

        // Avoid overwriting an already configured token file.
        if (file_exists($filename) && $this->config->has('api.token_file') && $this->resolveTokenFile($this->config->getStr('api.token_file')) === $filename) {
            throw new \RuntimeException('Failed to save API token: it would conflict with the existing api.token_file configuration.');
        }

        $this->fs->dumpFile($filename, $token);
        $this->fs->chmod($filename, 0o600);
    }

    /**
     * @return string
     */
    private function load(): string
    {
        $filename = $this->getFilename();
        if (file_exists($filename)) {
            return trim((string) file_get_contents($filename));
        }

        return '';
    }

    /**
     * @return string
     */
    private function getFilename(): string
    {
        return $this->config->getSessionDir(true) . DIRECTORY_SEPARATOR . 'api-token';
    }

    /**
     * Makes a relative path absolute, based on the user config dir.
     */
    private function resolveTokenFile(string $tokenFile): string
    {
        if (!str_starts_with($tokenFile, '/') && !str_starts_with($tokenFile, '\\')) {
            $tokenFile = $this->config->getUserConfigDir() . '/' . $tokenFile;
        }

        return $tokenFile;
    }
}
