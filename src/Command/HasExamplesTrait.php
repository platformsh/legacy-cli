<?php

namespace Platformsh\Cli\Command;

trait HasExamplesTrait
{
    /** @var array */
    protected $examples;

    /**
     * @param string $description
     * @param string $arguments
     *
     * @return self
     */
    protected function addExample($description, $arguments = '')
    {
        $this->examples[$arguments] = $description;

        return $this;
    }
}
