<?php

namespace Platformsh\Cli\Command;

trait HasExamplesTrait
{
    /** @var array{'commandline': string, 'description': string}[] */
    private $examples = [];

    /**
     * @param string $description
     * @param string $commandline
     *
     * @return self
     */
    protected function addExample($description, $commandline = '')
    {
        $this->examples[] = ['commandline' => $commandline, 'description' => $description];

        return $this;
    }

    /**
     * @return array
     */
    public function getExamples()
    {
        return $this->examples;
    }
}
