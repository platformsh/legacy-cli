<?php

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\Service\Config;

/**
 * Stores API tokens using the docker credential helpers.
 */
class CredentialHelperStorage implements StorageInterface {
    private readonly string $serverUrl;

    public function __construct(Config $config, private readonly Manager $manager)
    {
        $this->serverUrl = sprintf(
            '%s/%s/api-token',
            $config->get('application.slug'),
            $config->getSessionId()
        );
    }

    /**
     * @inheritDoc
     */
    public function getToken()
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
