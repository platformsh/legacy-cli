<?php

namespace Platformsh\Cli\Model\Metrics;

use Symfony\Component\Console\Helper\FormatterHelper;

class Format
{
    const ROUNDED = 'rounded';
    const ROUNDED_2P = 'rounded_2';
    const PERCENT = 'percent';
    const DISK = 'disk';
    const MEMORY = 'memory';

    const RED_WARNING_THRESHOLD = 90;
    const YELLOW_WARNING_THRESHOLD = 80;

    /**
     * @param float|null $pc
     * @param bool $warn
     * @return string
     */
    private static function formatPercent($pc, $warn = true)
    {
        if (null === $pc) {
            return '';
        }

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
     * @param string $format
     * @param float|null $value
     * @param bool $warn
     * @return string
     */
    public static function format($format, $value, $warn = true)
    {
        if (null === $value) {
            return '';
        }

        if (PHP_INT_MAX === (int) $value) {
            return '∞';
        }

        if ($format === self::ROUNDED) {
            return (string) round($value);
        }
        if ($format === self::ROUNDED_2P) {
            return (string) round($value, 2);
        }
        if ($format === self::PERCENT) {
            return self::formatPercent($value, $warn);
        }
        if ($format === self::DISK || $format === self::MEMORY) {
            return FormatterHelper::formatMemory((int) $value);
        }

        return '';
    }
}
