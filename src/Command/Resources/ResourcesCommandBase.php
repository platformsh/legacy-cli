<?php

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;

class ResourcesCommandBase extends CommandBase
{
    private readonly Config $config;

    #[Required]
    public function autowire(Config $config) : void
    {
        $this->config = $config;
    }

    public function isHidden(): bool
    {
        return !$this->config->get('api.sizing') || parent::isHidden();
    }
}
