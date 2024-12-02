<?php

namespace Platformsh\Cli\Event;

use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Contracts\EventDispatcher\Event;

class EnvironmentsChangedEvent extends Event
{
    /**
     * @param Project $project
     * @param Environment[] $environments
     */
    public function __construct(private readonly Project $project, private readonly array $environments)
    {
    }

    public function getProject()
    {
        return $this->project;
    }

    public function getEnvironments()
    {
        return $this->environments;
    }
}
