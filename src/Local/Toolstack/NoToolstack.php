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
            $this->output->writeln("Copying");
            if (file_exists($this->appRoot . '/' . $this->documentRoot)) {
                $this->fsHelper->copyAll($this->appRoot, $this->buildDir);
            }
            else {
                $this->fsHelper->copyAll($this->appRoot, $this->getWebRoot());
            }
        }

        $this->processSpecialDestinations();
    }
}
