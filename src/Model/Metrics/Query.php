<?php

namespace Platformsh\Cli\Model\Metrics;

/**
 * Represents a Metrics API query.
 */
class Query
{
    /** @var int Interval in seconds */
    private $interval;
    /** @var int Start timestamp */
    private $startTime;
    /** @var int End timestamp */
    private $endTime;
    /** @var string */
    private $collection;
    private array $fields = [];
    private array $filters = [];

    /**
     * @param int $interval
     * @return Query
     */
    public function setInterval($interval): static
    {
        $this->interval = $interval;
        return $this;
    }

    /**
     * @param int $startTime
     * @return Query
     */
    public function setStartTime($startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }

    /**
     * @param int $endTime
     * @return Query
     */
    public function setEndTime($endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }

    /**
     * @param string $collection
     * @return Query
     */
    public function setCollection($collection): static
    {
        $this->collection = $collection;
        return $this;
    }

    /**
     * @param string $name
     * @param string $expression
     * @return Query
     */
    public function addField($name, $expression): static
    {
        $this->fields[$name] = $expression;
        return $this;
    }

    /**
     * @param string $key
     * @param string $value
     * @return Query
     */
    public function addFilter($key, $value): static
    {
        $this->filters[$key] = $value;
        return $this;
    }

    /**
     * Returns the query as an array.
     * @return array
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
