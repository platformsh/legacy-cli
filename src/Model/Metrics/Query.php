<?php

namespace Platformsh\Cli\Model\Metrics;

/**
 * Represents a Metrics API query.
 */
class Query
{
    /** @var array|null */
    private $services = null;
    /** @var array */
    private $types = array();
    /** @var array */
    private $aggs = array();
    /** @var int */
    private $startTime;
    /** @var int */
    private $endTime;

    /**
     * @param int $startTime
     * @param int $endTime
     */
    public function __construct($startTime, $endTime)
    {
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    /**
     * @param TimeSpec $timeSpec
     * @return self
     */
    public static function fromTimeSpec($timeSpec)
    {
        return new self($timeSpec->getStartTime(), $timeSpec->getEndTime());
    }

    /**
     * @param array|null $services
     * @return self
     */
    public function setServices($services)
    {
        $this->services = $services;

        return $this;
    }

    /**
     * @param array $types
     * @return self
     */
    public function setTypes($types)
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @param array $aggs
     * @return self
     */
    public function setAggs($aggs)
    {
        $this->aggs = $aggs;

        return $this;
    }

    /**
     * @return array
     */
    public function asArray()
    {
        $query = array(
            'from' => $this->startTime,
            'to' => $this->endTime,
        );

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

    /**
     * @return string
     */
    public function asString()
    {
        return '?' . http_build_query($this->asArray(), '', '&', PHP_QUERY_RFC3986);
    }
}
