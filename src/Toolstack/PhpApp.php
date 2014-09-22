<?php

namespace CommerceGuys\Platform\Cli\Toolstack;

use CommerceGuys\Platform\Cli;
use Symfony\Component\Console;

class PhpApp extends BaseApp implements LocalBuildInterface
{
    public static function detect($appRoot, $settings) {}

    public function build() {}
    
}
