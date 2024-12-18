<?php

namespace Platformsh\Cli\Model\Metrics;

/**
 * Represents a Metrics API query.
 */
final class Query
{
    /** @var int Interval in seconds */
    private int $interval = 0;
    /** @var int Start timestamp */
    private int $startTime = 0;
    /** @var int End timestamp */
    private int $endTime = 0;
    /** @var string */
    private string $collection = '';
    /** @var array<string, string> */
    private array $fields = [];
    /** @var array<string, string> */
    private array $filters = [];

    public function setInterval(int $interval): self
    {
        $this->interval = $interval;
        return $this;
    }

    public function setStartTime(int $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function setEndTime(int $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function setCollection(string $collection): self
    {
        $this->collection = $collection;
        return $this;
    }

    public function addField(string $name, string $expression): self
    {
        $this->fields[$name] = $expression;
        return $this;
    }

    public function addFilter(string $key, string $value): self
    {
        $this->filters[$key] = $value;
        return $this;
    }

    /**
     * Returns the query as an array.
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        $query = [
            'stream' => [
                'stream' => 'metrics',
                'collection' => $this->collection,
            ],
            'interval' => $this->interval . 's',
            'fields' => [],
            'range' => [
                'from' => date('Y-m-d\TH:i:s.uP', $this->startTime),
                'to' => date('Y-m-d\TH:i:s.uP', $this->endTime),
            ],
        ];
        foreach ($this->fields as $name => $expr) {
            $query['fields'][] = ['name' => $name, 'expr' => $expr];
        }
        foreach ($this->filters as $key => $value) {
            $query['filters'][] = ['key' => $key, 'value' => $value];
        }
        return $query;
    }
}
