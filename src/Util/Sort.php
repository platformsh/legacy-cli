<?php

namespace Platformsh\Cli\Util;

final class Sort
{
    /**
     * Compares values for use as a sort callback.
     *
     * If the values are strings, the comparison will be case-insensitive and
     * "natural". Otherwise the default PHP comparison is used.
     *
     * @param mixed $a
     * @param mixed $b
     * @return int
     */
    public static function compare($a, $b)
    {
        if (\is_string($a)) {
            return \strnatcasecmp($a, $b);
        }
        // TODO replace with spaceship operator for PHP 7+
        return $a == $b ? 0 : ($a > $b ? 1 : -1);
    }

    /**
     * Compares domains as a sorting function. Used to sort region IDs.
     *
     * @param string $regionA
     * @param string $regionB
     *
     * @return int
     */
    public static function compareDomains($regionA, $regionB)
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
     *
     * @param object[] $objects
     * @param string $property
     * @param bool $reverse
     *
     * @return void
     */
    public static function sortObjects(array &$objects, $property, $reverse = false)
    {
        uasort($objects, function ($a, $b) use ($property, $reverse) {
            if (!property_exists($a, $property) || !property_exists($b, $property)) {
                throw new \InvalidArgumentException('Cannot sort: property not found: ' . $property);
            }
            $cmp = self::compare($a->$property, $b->$property);
            return $reverse ? -$cmp : $cmp;
        });
    }
}
