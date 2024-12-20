<?php

namespace Platformsh\Cli\Command;

trait HasExamplesTrait
{
    /** @var array<array{'commandline': string, 'description': string}> */
    private array $examples = [];

    protected function addExample(string $description, string $commandline = ''): self
    {
        $this->examples[] = ['commandline' => $commandline, 'description' => $description];

        return $this;
    }

    /**
     * @return array<array{'commandline': string, 'description': string}>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }
}
