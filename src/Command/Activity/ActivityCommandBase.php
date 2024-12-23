<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Activity;

use Platformsh\Cli\Command\CommandBase;

class ActivityCommandBase extends CommandBase
{
    protected const STATE_VALUES = ['in_progress', 'pending', 'complete', 'cancelled'];
    protected const RESULT_VALUES = ['success', 'failure'];
}
