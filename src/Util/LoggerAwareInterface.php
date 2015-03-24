<?php

namespace Platformsh\Cli\Util;

use Psr\Log\LoggerInterface;

interface LoggerAwareInterface
{
    /**
     * Set the logger to use.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger);
}
