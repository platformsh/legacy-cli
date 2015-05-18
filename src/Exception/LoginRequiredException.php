<?php

namespace Platformsh\Cli\Exception;

class LoginRequiredException extends \RuntimeException
{
    protected $message = 'Not logged in.';
    protected $code = 3;
}
