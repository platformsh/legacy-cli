<?php

namespace Platformsh\Cli\Model\RemoteContainer;

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
    public function getSshUrl($instance = '');

    /**
     * Returns the container's name (machine or human-readable).
     *
     * @return string
     */
    public function getName();

    /**
     * Gets the container config.
     *
     * @return \Platformsh\Cli\Model\AppConfig
     */
    public function getConfig();
}
