<?php

namespace Platformsh\Cli\Model\Metrics;

class SourceFieldPercentage
{
    /**
     * @var SourceField
     */
    public $value;

    /**
     * @var SourceField
     */
    public $limit;

    /**
     * @param SourceField $value
     * @param SourceField $limit
     */
    public function __construct($value, $limit)
    {
        $this->value = $value;
        $this->limit = $limit;
    }
}
