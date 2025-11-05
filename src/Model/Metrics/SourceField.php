<?php

namespace Platformsh\Cli\Model\Metrics;

class SourceField
{
    /**
     * @var string
     */
    public $source;

    /**
     * @var string
     */
    public $aggregation;

    /**
     * @var string|null
     */
    public $mountpoint;

    /**
     * @param string $source
     * @param string $aggregation
     * @param string|null $mountpoint
     */
    public function __construct($source, $aggregation, $mountpoint = null)
    {
        $this->source = $source;
        $this->aggregation = $aggregation;
        $this->mountpoint = $mountpoint;
    }
}
