<?php

namespace Platformsh\Cli\Local\Toolstack;

class NoToolstack extends ToolstackBase
{
    public function detect($appRoot)
    {
        return true;
    }

    public function build()
    {
        $this->copyToBuildDir();

        $this->processSpecialDestinations();
    }
}
