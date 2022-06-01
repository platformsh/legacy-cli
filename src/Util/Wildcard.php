<?php

namespace Platformsh\Cli\Util;

class Wildcard
{
    /**
     * Selects strings in a list matching a list of wildcards.
     *
     * @param string[] $subjects
     * @param string[] $wildcards
     *
     * @return string[]
     */
    public static function select(array $subjects, $wildcards)
    {
        $found = [];
        foreach ($wildcards as $wildcard) {
            $pattern = '/^' . \str_replace('%', '.*', \preg_quote($wildcard, '/')) . '$/';
            $found = \array_merge($found, \preg_grep($pattern, $subjects));
        }
        return \array_unique($found);
    }
}
