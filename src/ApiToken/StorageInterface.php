<?php

namespace Platformsh\Cli\ApiToken;

interface StorageInterface {
    /**
     * Loads the API token.
     */
    public function getToken(): string;

    /**
     * Stores an API token.
     *
     * @param string $value
     */
    public function storeToken(string $value): void;

    /**
     * Deletes the saved token.
     */
    public function deleteToken(): void;
}
