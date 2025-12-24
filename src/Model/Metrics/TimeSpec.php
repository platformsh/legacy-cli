<?php

namespace Platformsh\Cli\Model\Metrics;

class TimeSpec
{
    /** @var int */
    private $startTime;
    /** @var int */
    private $endTime;
    /** @var int|null */
    private $interval;

    /**
     * @param int $startTime start time (UNIX timestamp)
     * @param int $endTime   end time (UNIX timestamp)
     * @param int|null $interval
     */
    public function __construct($startTime, $endTime, $interval = null)
    {
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->interval = $interval;
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
     * @return int|null
     */
    public function getInterval()
    {
        return $this->interval;
    }
}
