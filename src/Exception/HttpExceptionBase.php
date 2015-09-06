<?php

namespace Platformsh\Cli\Exception;

use GuzzleHttp\Message\RequestInterface;

class HttpExceptionBase extends \RuntimeException
{
    /**
     * @param string           $message
     * @param RequestInterface $request
     */
    public function __construct($message = null, RequestInterface $request = null)
    {
        $message = $message ?: $this->message;
        if ($request !== null) {
            $message .= "\nRequest URL: " . $request->getUrl();
        }
        parent::__construct($message, $this->code);
    }
}
