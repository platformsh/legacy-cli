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
}
