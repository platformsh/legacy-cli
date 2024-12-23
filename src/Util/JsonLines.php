<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class JsonLines
{
    /**
     * Decodes a JSON lines string and returns an array of associative arrays.
     *
     * @param string $str
     *
     * @return array<array<mixed>>
     */
    public static function decode(string $str): array
    {
        $items = [];
        foreach (explode("\n", trim($str, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $item = \json_decode($line, true);
            if ($item === null && \json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode JSON with message: ' . \json_last_error_msg() . ':' . "\n" . $line);
            }
            $items[] = $item;
        }

        return $items;
    }
}
