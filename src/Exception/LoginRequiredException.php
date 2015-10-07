<?php

namespace Platformsh\Cli\Exception;

class LoginRequiredException extends HttpException
{
    protected $message = 'Not logged in.';
    protected $code = 3;
}
