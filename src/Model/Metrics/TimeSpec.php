<?php

namespace Platformsh\Cli\Model\Metrics;

class TimeSpec
{
    /** @var int */
    private $startTime;
    /** @var int */
    private $endTime;

    /**
     * @param int $startTime start time (UNIX timestamp)
     * @param int $endTime   end time (UNIX timestamp)
     */
    public function __construct($startTime, $endTime)
    {
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    /**
     * @return int
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * @return int
     */
    public function getEndTime()
    {
        return $this->endTime;
    }
}
