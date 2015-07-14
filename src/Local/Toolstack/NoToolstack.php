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
        $this->buildInPlace = true;

        if ($this->copy) {
            $buildDir = $this->getBuildDir();
            $this->output->writeln("Copying");
            $this->fsHelper->copyAll($this->appRoot, $buildDir);
        }

        $this->processSpecialDestinations();
    }
}
