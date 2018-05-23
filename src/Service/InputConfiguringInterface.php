<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputDefinition;

interface InputConfiguringInterface
{
    public function configureInput(InputDefinition $definition);
}
