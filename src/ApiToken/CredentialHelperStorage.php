<?php

declare(strict_types=1);

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\Service\Config;

/**
 * Stores API tokens using the docker credential helpers.
 */
readonly class CredentialHelperStorage implements StorageInterface
{
    private string $serverUrl;

    public function __construct(Config $config, private Manager $manager)
    {
        $this->serverUrl = sprintf(
            '%s/%s/api-token',
            $config->getStr('application.slug'),
            $config->getSessionId(),
        );
    }

    /**
     * @inheritDoc
     */
    public function getToken(): string
    {
        return $this->manager->get($this->serverUrl) ?: '';
    }

    /**
     * @inheritDoc
     */
    public function storeToken($value): void
    {
        $this->manager->store($this->serverUrl, $value);
    }

    /**
     * @inheritDoc
     */
    public function deleteToken(): void
    {
        $this->manager->erase($this->serverUrl);
    }
}
