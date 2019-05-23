<?php

namespace Platformsh\Cli\Model\SshDestination;

/**
 * Represents a resource that provides an SSH server.
 */
interface SshDestinationInterface
{
    /**
     * Returns the destination's SSH URL.
     *
     * @return string
     */
    public function getSshUrl();

    /**
     * Returns the destination's name (machine or human-readable).
     *
     * @return string
     */
    public function getName();

    /**
     * Returns the destination type (a human-readable string).
     *
     * @return string
     */
    public function getType();

    /**
     * Lists file mounts on the destination.
     *
     * @return array
     *   An associative array of mounts, taken from the configuration in the
     *   app config file (.platform.app.yaml).
     */
    public function getMounts();
}
