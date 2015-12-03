<?php

namespace Platformsh\Cli\Exception;

class ProjectNotFoundException extends \RuntimeException
{
    protected $code = 9;
}
