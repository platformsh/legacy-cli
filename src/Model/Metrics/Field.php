<?php

namespace Platformsh\Cli\Model\Metrics;

use Symfony\Component\Console\Helper\FormatterHelper;

class Field
{
    const RED_WARNING_THRESHOLD = 90; // percent
    const YELLOW_WARNING_THRESHOLD = 80; // percent

    const FORMAT_ROUNDED = 'rounded';
    const FORMAT_ROUNDED_2DP = 'rounded_2';
    const FORMAT_PERCENT = 'percent';
    const FORMAT_DISK = 'disk';
    const FORMAT_MEMORY = 'memory';

    /** @var string */
    private $name;

    /** @var string */
    private $format;

    public function __construct($name, $format)
    {
        $this->name = $name;
        $this->format = $format;
    }

    public function getName()
    {
        return $this->name;
    }

    /**
     * Formats a float as a percentage.
     *
     * @param float $pc
     * @param bool $warn
     *
     * @return string
     */
    private function formatPercent($pc, $warn = true)
    {
        if ($warn) {
            if ($pc >= self::RED_WARNING_THRESHOLD) {
                return \sprintf('<options=bold;fg=red>%.1f%%</>', $pc);
            }
            if ($pc >= self::YELLOW_WARNING_THRESHOLD) {
                return \sprintf('<options=bold;fg=yellow>%.1f%%</>', $pc);
            }
        }
        return \sprintf('%.1f%%', $pc);
    }

    /**
     * Formats a value according to the field format.
     *
     * @param Sketch $value
     * @param bool $warn
     *   Adds colors if the value is over a threshold.
     *
     * @return string
     */
    public function format(Sketch $value, $warn = true)
    {
        if ($value->isInfinite()) {
            return '∞';
        }
        switch ($this->format) {
            case self::FORMAT_ROUNDED:
                return (string) round($value->average());
            case self::FORMAT_ROUNDED_2DP:
                return (string) round($value->average(), 2);
            case self::FORMAT_PERCENT:
                return $this->formatPercent($value->average(), $warn);
            case self::FORMAT_DISK:
            case self::FORMAT_MEMORY:
                return FormatterHelper::formatMemory($value->average());
        }
        throw new \InvalidArgumentException('Formatter not found: ' . $this->format);
    }
}
