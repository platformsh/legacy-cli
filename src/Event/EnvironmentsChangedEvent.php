<?php

declare(strict_types=1);

namespace Platformsh\Cli\Event;

use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Contracts\EventDispatcher\Event;

class EnvironmentsChangedEvent extends Event
{
    /** @param Environment[] $environments */
    public function __construct(private readonly Project $project, private readonly array $environments) {}

    public function getProject(): Project
    {
        return $this->project;
    }

    /** @return Environment[] */
    public function getEnvironments(): array
    {
        return $this->environments;
    }
}
