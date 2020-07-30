<?php

namespace Platformsh\Cli\ApiToken;

interface StorageInterface {
    /**
     * Loads the API token.
     *
     * @return string
     */
    public function getToken();

    /**
     * Stores an API token.
     *
     * @param string $value
     */
    public function storeToken($value);

    /**
     * Deletes the saved token.
     */
    public function deleteToken();
}
