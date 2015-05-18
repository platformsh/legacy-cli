<?php

namespace Platformsh\Cli\Exception;

class RootNotFoundException extends \RuntimeException
{
    protected $message = 'Project root not found. This can only be run from inside a project directory.';
    protected $code = 2;
}
