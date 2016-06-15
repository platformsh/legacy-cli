<?php
namespace Platformsh\Cli\Command;

interface CanHideInListInterface
{
    /**
     * Whether the command should be hidden in lists of commands.
     *
     * @return bool
     */
    public function isHiddenInList();
}
