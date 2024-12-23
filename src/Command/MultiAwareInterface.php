<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

interface MultiAwareInterface
{
    /**
     * Whether the command can be run multiple times in one process.
     */
    public function canBeRunMultipleTimes(): bool;

    public function setRunningViaMulti(bool $runningViaMulti = true): void;
}
