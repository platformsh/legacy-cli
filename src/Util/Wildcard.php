<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class Wildcard
{
    public const HELP = 'The % or * characters may be used as a wildcard.';

    /**
     * Selects strings in a list matching a list of wildcards.
     *
     * @param string[] $subjects
     * @param string[] $wildcards
     *
     * @return string[]
     */
    public static function select(array $subjects, array $wildcards): array
    {
        $found = [];
        foreach ($wildcards as $wildcard) {
            $pattern = '/^' . \str_replace(['%', '\\*'], '.*', \preg_quote($wildcard, '/')) . '$/';
            $found = \array_merge($found, (array) \preg_grep($pattern, $subjects));
        }
        return \array_unique($found);
    }
}
