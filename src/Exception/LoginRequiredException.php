<?php

namespace Platformsh\Cli\Exception;

class LoginRequiredException extends HttpExceptionBase
{
    protected $message = 'Not logged in.';
    protected $code = 3;
}
