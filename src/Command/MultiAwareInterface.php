<?php
namespace Platformsh\Cli\Command;

interface MultiAwareInterface
{
    /**
     * Whether the command can be run multiple times in one process.
     *
     * @return bool
     */
    public function canBeRunMultipleTimes();
}
