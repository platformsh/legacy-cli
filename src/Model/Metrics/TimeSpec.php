<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

readonly class TimeSpec
{
    /**
     * @param int $startTime Start time (UNIX timestamp).
     * @param int $endTime End time (UNIX timestamp).
     * @param int $interval Interval (seconds).
     */
    public function __construct(private int $startTime, private int $endTime, private int $interval) {}

    public function getStartTime(): int
    {
        return $this->startTime;
    }

    public function getEndTime(): int
    {
        return $this->endTime;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
