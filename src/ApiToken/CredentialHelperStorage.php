<?php

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\Service\Config;

/**
 * Stores API tokens using the docker credential helpers.
 */
class CredentialHelperStorage implements StorageInterface {
    private $manager;
    private $serverUrl;

    public function __construct(Config $config, Manager $manager)
    {
        $this->manager = $manager;
        $this->serverUrl = $config->get('application.slug') . '/api-token';
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
    public function storeToken($value)
    {
        $this->manager->store($this->serverUrl, $value);
    }

    /**
     * @inheritDoc
     */
    public function deleteToken()
    {
        $this->manager->erase($this->serverUrl);
    }
}
