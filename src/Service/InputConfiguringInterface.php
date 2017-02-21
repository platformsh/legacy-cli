<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputDefinition;

interface InputConfiguringInterface
{
    public static function configureInput(InputDefinition $definition);
}
