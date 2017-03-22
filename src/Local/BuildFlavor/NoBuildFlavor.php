<?php

namespace Platformsh\Cli\Local\BuildFlavor;

class NoBuildFlavor extends BuildFlavorBase
{
    public function getStacks()
    {
        return [];
    }

    public function getKeys()
    {
        return ['none', 'default'];
    }

    public function build()
    {
        $this->copyToBuildDir();

        $this->processSpecialDestinations();
    }
}
