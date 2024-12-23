<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class InvalidConfigException extends \InvalidArgumentException
{
    protected $code = 4;

    public function __construct(string $message = '', string $filename = '', string $configKey = '', ?\Exception $previous = null)
    {
        if ($configKey !== '') {
            $message .= "\nin config key: $configKey";
        }
        if ($filename !== '') {
            $path = realpath($filename) ?: $filename;
            $message .= "\nin file: $path";
        }

        parent::__construct($message, 0, $previous);
    }
}
