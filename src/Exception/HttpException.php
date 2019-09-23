<?php

namespace Platformsh\Cli\Exception;

class HttpException extends \RuntimeException
{
    /**
     * @param string          $message
     * @param \Throwable|null $previous
     */
    public function __construct($message = 'An API error occurred', $previous = null)
    {
        parent::__construct($message, $this->code, $previous);
    }
}
