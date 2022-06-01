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
     * @return string
     */
    public function getSshUrl();

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
