<?php

namespace Platformsh\Cli\Exception;

class DependencyVersionMismatchException extends \RuntimeException
{
    protected $code = 8;
}
