<?php

namespace CommerceGuys\Platform\Cli\Input;

use Symfony\Component\Console\Input\ArgvInput as ArgvInputBase;

class ArgvInput extends ArgvInputBase
{

    /**
     * {@inheritdoc}
     */
    protected function parse()
    {
        // The environment:drush command acts as a wrapper for drush, sending
        // it all unknown arguments and options.
        // Hence the need to suppress the exceptions in that case.
        try {
            parent::parse();
        }
        catch (\RuntimeException $e) {
            $commandName = $this->getFirstArgument();
            if ($commandName != 'drush') {
                throw $e;
            }
        }
    }
}
