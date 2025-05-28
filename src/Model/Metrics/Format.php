<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model\Metrics;

use Symfony\Component\Console\Helper\FormatterHelper;

enum Format: string
{
    case Rounded = 'rounded';
    case Rounded2p = 'rounded_2';
    case Percent = 'percent';
    case Disk = 'disk';
    case Memory = 'memory';

    private const RED_WARNING_THRESHOLD = 90; // percent
    private const YELLOW_WARNING_THRESHOLD = 80; // percent

    private function formatPercent(?float $pc, bool $warn = true): string
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

    public function format(?float $value, bool $warn = true): string
    {
        if (null === $value) {
            return '';
        }

        if (PHP_INT_MAX === (int) $value) {
            return '∞';
        }

        return match ($this) {
            Format::Rounded => (string) round($value),
            Format::Rounded2p => (string) round($value, 2),
            Format::Percent => $this->formatPercent($value, $warn),
            Format::Disk, Format::Memory => FormatterHelper::formatMemory((int) $value),
        };
    }
}
