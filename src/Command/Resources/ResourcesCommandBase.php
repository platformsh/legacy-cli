<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;

class ResourcesCommandBase extends CommandBase
{
    private Config $config;

    #[Required]
    public function autowire(Config $config): void
    {
        $this->config = $config;
    }

    public function isHidden(): bool
    {
        return !$this->config->getBool('api.sizing') || parent::isHidden();
    }
}
