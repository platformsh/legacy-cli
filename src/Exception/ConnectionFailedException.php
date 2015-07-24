<?php

namespace Platformsh\Cli\Exception;

class ConnectionFailedException extends \RuntimeException
{
    protected $message = 'No Internet connection available.';
    protected $code = 5;
}
