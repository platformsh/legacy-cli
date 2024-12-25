<?php

declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

class Symfony extends Composer
{
    public function getKeys(): array
    {
        return ['symfony'];
    }

    public function install(): void
    {
        parent::install();
        $this->copyGitIgnore('symfony/gitignore-standard');
    }
}
