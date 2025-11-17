<?php

namespace Platformsh\Cli\Model\Metrics;

class Field
{
    /**
     * @var string
     */
    public $format;

    /**
     * @var SourceField|SourceFieldPercentage
     */
    public $value;

    /**
     * @var bool
     */
    public $warn;

    /**
     * @param string $format
     * @param SourceField|SourceFieldPercentage $value
     * @param bool $warn
     */
    public function __construct($format, $value, $warn = true)
    {
        $this->format = $format;
        $this->value = $value;
        $this->warn = $warn;
    }
}
