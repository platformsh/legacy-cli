<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\RemoteContainer;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Deployment\RuntimeOperation;

/**
 * Represents a resource that provides an SSH server.
 */
interface RemoteContainerInterface
{
    /**
     * Returns the container's SSH URL.
     *
     * @param string $instance The instance ID (usually numeric, starting with '0').
     *
     * @return string
     */
    public function getSshUrl(string $instance = ''): string;

    /**
     * Returns the container's name (machine or human-readable).
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Gets the container config.
     *
     * @return AppConfig
     */
    public function getConfig(): AppConfig;

    /**
     * Returns runtime operations defined on the app or worker.
     *
     * @return array<string, RuntimeOperation>
     */
    public function getRuntimeOperations(): array;
}
