<?php

namespace Platformsh\Cli\Util;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait HasLoggerTrait
{

    /** @var LoggerInterface|null */
    protected $logger;

    /**
     * Implements LoggerAwareInterface::setOutput().
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        if (!isset($this->logger)) {
            $this->logger = new NullLogger();
        }
        return $this->logger;
    }
}
