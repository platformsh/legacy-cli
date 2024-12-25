<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

class NoBuildFlavor extends BuildFlavorBase
{
    public function getStacks(): array
    {
        return [];
    }

    public function getKeys(): array
    {
        return ['none', 'default'];
    }

    public function build(): void
    {
        $this->copyToBuildDir();

        $this->processSpecialDestinations();
    }
}
