<?php

namespace Platformsh\Cli\ApiToken;

use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\Service\Config;

/**
 * Stores and retrieves an API token.
 */
class Storage
{
    /**
     * @param Config $config
     *
     * @return StorageInterface
     */
    public static function factory(Config $config) {
        $manager = new Manager($config);
        if ($manager->isSupported()) {
            return new CredentialHelperStorage($config, $manager);
        }

        return new FileStorage($config);
    }
}
