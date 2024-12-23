<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class HttpException extends \RuntimeException
{
    public function __construct(?string $message = null, ?\Throwable $previous = null)
    {
        $message = $message ?: 'An HTTP error occurred';

        parent::__construct($message, $this->code, $previous);
    }
}
