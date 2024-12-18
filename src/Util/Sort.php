<?php

namespace Platformsh\Cli\Util;

final class Sort
{
    /**
     * Compares values for use as a sort callback.
     *
     * If the values are strings, the comparison will be case-insensitive and
     * "natural". Otherwise the default PHP comparison is used.
     */
    public static function compare(mixed $a, mixed $b, bool $reverse = false): int
    {
        if (\is_string($a)) {
            $value = \strnatcasecmp($a, (string) $b);
        } else {
            // TODO replace with spaceship operator for PHP 7+
            $value = $a == $b ? 0 : ($a > $b ? 1 : -1);
        }
        return $reverse ? -$value : $value;
    }

    /**
     * Compares domains as a sorting function. Used to sort region IDs.
     */
    public static function compareDomains(string $regionA, string $regionB): int
    {
        if (strpos($regionA, '.') && strpos($regionB, '.')) {
            $partsA = explode('.', $regionA, 2);
            $partsB = explode('.', $regionB, 2);
            return (\strnatcasecmp($partsA[1], $partsB[1]) * 10) + \strnatcasecmp($partsA[0], $partsB[0]);
        }
        return \strnatcasecmp($regionA, $regionB);
    }

    /**
     * Sorts arrays of objects by a property.
     *
     * Array keys will be preserved.
     */
    public static function sortObjects(array &$objects, string $property, bool $reverse = false): void
    {
        uasort($objects, function ($a, $b) use ($property, $reverse) {
            if (!property_exists($a, $property) || !property_exists($b, $property)) {
                throw new \InvalidArgumentException('Cannot sort: property not found: ' . $property);
            }
            return self::compare($a->$property, $b->$property, $reverse);
        });
    }
}
