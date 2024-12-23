<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Command\CommandBase;

abstract class TunnelCommandBase extends CommandBase
{
    protected bool $canBeRunMultipleTimes = false;
}
