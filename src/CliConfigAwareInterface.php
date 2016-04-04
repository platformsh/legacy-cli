<?php

namespace Platformsh\Cli;

/**
 * Helpers implementing this interface can be injected with a CLI config object.
 */
interface CliConfigAwareInterface
{
    /**
     * Sets the CLI configuration object.
     *
     * @param CliConfig $config
     */
    public function setCliConfig(CliConfig $config);
}
