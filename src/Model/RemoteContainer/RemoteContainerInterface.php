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
     * Returns the type of remote container (a human-readable string).
     *
     * Example: 'app' or 'worker'.
     *
     * @return string
     */
    public function getType();

    /**
     * Gets the container config.
     *
     * @return \Platformsh\Cli\Model\AppConfig
     */
    public function getConfig();

    /**
     * Lists file mounts on the container.
     *
     * @return array
     *   An associative array of mounts, taken from the configuration in the
     *   app config file (.platform.app.yaml).
     */
    public function getMounts();
}
