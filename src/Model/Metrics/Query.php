<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

/**
 * Represents a Metrics API query.
 */
final class Query
{
    /** @var array<String>|null */
    private ?array $services = null;
    /** @var array<String> */
    private array $types = [];

    public function __construct(
        private int $startTime,
        private int $endTime,
    ) {}

    public static function fromTimeSpec(TimeSpec $timeSpec): self
    {
        return new self($timeSpec->getStartTime(), $timeSpec->getEndTime());
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

    /** @return array<string, array<string>|int|string> */
    public function asArray(): array
    {
        $query = [
            'from' => $this->startTime,
            'to' => $this->endTime,
        ];

        if (!empty($this->services)) {
            $query['services_mode'] = '1';
            $query['services'] = $this->services;
        }

        if (!empty($this->types)) {
            $query['types'] = $this->types;
        }

        return $query;
    }

    public function asString(): string
    {
        return '?' . http_build_query($this->asArray(), '', '&', PHP_QUERY_RFC3986);
    }
}
