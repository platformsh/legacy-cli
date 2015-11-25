<?php

namespace Platformsh\Cli\Exception;

class DependencyMissingException extends \RuntimeException
{
    protected $code = 7;
}
