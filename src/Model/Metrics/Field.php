<?php

namespace Platformsh\Cli\Model\Metrics;

use Symfony\Component\Console\Helper\FormatterHelper;

class Field
{
    public const RED_WARNING_THRESHOLD = 90; // percent
    public const YELLOW_WARNING_THRESHOLD = 80; // percent

    public const FORMAT_ROUNDED = 'rounded';
    public const FORMAT_ROUNDED_2DP = 'rounded_2';
    public const FORMAT_PERCENT = 'percent';
    public const FORMAT_DISK = 'disk';
    public const FORMAT_MEMORY = 'memory';

    public function __construct(private readonly string $name, private readonly string $format) {}

    public function getName(): string
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
    private function formatPercent(float $pc, bool $warn = true): string
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
    public function format(Sketch $value, bool $warn = true): string
    {
        if ($value->isInfinite()) {
            return '∞';
        }
        return match ($this->format) {
            self::FORMAT_ROUNDED => (string) round($value->average()),
            self::FORMAT_ROUNDED_2DP => (string) round($value->average(), 2),
            self::FORMAT_PERCENT => $this->formatPercent($value->average(), $warn),
            self::FORMAT_DISK, self::FORMAT_MEMORY => FormatterHelper::formatMemory((int) $value->average()),
            default => throw new \InvalidArgumentException('Formatter not found: ' . $this->format),
        };
    }
}
