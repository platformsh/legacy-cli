<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class DependencyMissingException extends \RuntimeException
{
    public function __construct($message = 'Dependency missing', ?\Exception $previous = null)
    {
        parent::__construct($message, 7, $previous);
    }
}
