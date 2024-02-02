<?php

namespace Platformsh\Cli\Util;

class StringUtil
{
    /**
     * Finds a substring between two delimiters.
     *
     * @param string $str
     * @param string $begin
     * @param string $end
     *
     * @return string|null
     *   The substring, or null if the delimiters are not found.
     */
    public static function between($str, $begin, $end)
    {
        $first = \strpos($str, $begin);
        $last = \strrpos($str, $end, $first);
        if ($first === false || $last === false) {
            return null;
        }
        $offset = $first + \strlen($begin);
        $length = $last - $first - \strlen($begin);
        return \substr($str, $offset, $length);
    }

    /**
     * Formats a list of items.
     *
     * @param string[] $items
     * @param string $before
     * @param string $after
     * @param string $andOr
     * @param string $separator
     *
     * @return string
     */
    public static function formatItemList($items, $before = '', $after = '', $andOr = ' or ', $separator = ', ')
    {
        if (count($items) === 0) {
            return '';
        }
        $items = array_map(function ($i) use ($before, $after) { return $before . $i . $after; }, $items);
        if (count($items) === 1) {
            return reset($items);
        }
        $last = array_pop($items);
        return implode($separator, $items) . ($andOr ?: $separator) . $last;
    }
}
