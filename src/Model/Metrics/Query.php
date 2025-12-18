<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

/**
 * Represents a Metrics API query.
 */
final class Query
{
    /** @var array<string>|null */
    private ?array $services = null;
    /** @var array<string> */
    private array $types = [];
    /** @var array<string> */
    private array $aggs = [];

    public function __construct(
        private int $startTime,
        private int $endTime,
        private ?int $interval,
    ) {}

    public static function fromTimeSpec(TimeSpec $timeSpec): self
    {
        return new self($timeSpec->getStartTime(), $timeSpec->getEndTime(), $timeSpec->getInterval());
    }

    /** @param array<String>|null $services */
    public function setServices(?array $services): self
    {
        $this->services = $services;

        return $this;
    }

    /** @param array<String> $types */
    public function setTypes(array $types): self
    {
        $this->types = $types;

        return $this;
    }

    /** @param array<String> $aggs */
    public function setAggs(array $aggs): self
    {
        $this->aggs = $aggs;

        return $this;
    }

    /** @return array<string, array<string>|int|string> */
    public function asArray(): array
    {
        $query = [
            'from' => $this->startTime,
            'to' => $this->endTime,
        ];

        if (null !== $this->interval) {
            $query['grain'] = $this->interval;
        }

        if (!empty($this->services)) {
            $query['services_mode'] = '1';
            $query['services'] = $this->services;
        }

        if (!empty($this->types)) {
            $query['types'] = $this->types;
        }

        if (!empty($this->aggs)) {
            $query['aggs'] = $this->aggs;
        }

        return $query;
    }

    public function asString(): string
    {
        return '?' . http_build_query($this->asArray(), '', '&', PHP_QUERY_RFC3986);
    }
}
