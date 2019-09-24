<?php

namespace Platformsh\Cli\Exception;

class HttpException extends \RuntimeException
{
    /**
     * @param string|null     $message
     * @param \Throwable|null $previous
     */
    public function __construct($message = null, $previous = null)
    {
        $message = $message ?: 'An HTTP error occurred';

        parent::__construct($message, $this->code, $previous);
    }
}
