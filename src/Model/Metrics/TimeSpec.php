<?php

namespace Platformsh\Cli\Model\Metrics;

class TimeSpec
{
    /**
     * @param int $startTime Start time (UNIX timestamp).
     * @param int $endTime End time (UNIX timestamp).
     * @param int $interval Interval (seconds).
     */
    public function __construct(private $startTime, private $endTime, private $interval)
    {
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

    /**
     * @return int
     */
    public function getInterval()
    {
        return $this->interval;
    }
}
