<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class StringUtil
{
    /**
     * Finds a substring between two delimiters.
     *
     * @return string|null
     *   The substring, or null if the delimiters are not found.
     */
    public static function between(string $str, string $begin, string $end): ?string
    {
        $first = \strpos($str, $begin);
        if ($first === false) {
            return null;
        }
        $last = \strrpos($str, $end, $first);
        if ($last === false) {
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
     */
    public static function formatItemList(array $items, string $before = '', string $after = '', string $andOr = ' or ', string $separator = ', '): string
    {
        if (count($items) === 0) {
            return '';
        }
        $items = array_map(fn($i): string => $before . $i . $after, $items);
        if (count($items) === 1) {
            return reset($items);
        }
        $last = array_pop($items);
        return implode($separator, $items) . ($andOr ?: $separator) . $last;
    }
}
