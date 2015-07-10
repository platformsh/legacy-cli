<?php

namespace Platformsh\Cli\Exception;

class InvalidConfigException extends \InvalidArgumentException
{
    protected $code = 4;
}
