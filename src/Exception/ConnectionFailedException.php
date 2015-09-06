<?php

namespace Platformsh\Cli\Exception;

class ConnectionFailedException extends HttpExceptionBase
{
    protected $message = 'No Internet connection available.';
    protected $code = 5;
}
