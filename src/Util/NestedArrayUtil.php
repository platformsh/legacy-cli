<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

class NestedArrayUtil
{
    /**
     * Get a nested value in an array.
     *
     * @param array<string, mixed> $array
     * @param string[] $parents
     * @param bool $keyExists
     *
     * @return mixed
     * @noinspection PhpMissingParamTypeInspection
     * @see Copied from \Drupal\Component\Utility\NestedArray::getValue()
     */
    public static function &getNestedArrayValue(array &$array, array $parents, &$keyExists = false): mixed
    {
        $ref = &$array;
        foreach ($parents as $parent) {
            if (is_array($ref) && array_key_exists($parent, $ref)) {
                $ref = &$ref[$parent];
            } else {
                $keyExists = false;
                $null = null;
                return $null;
            }
        }
        $keyExists = true;

        return $ref;
    }

    /**
     * Sets a nested value in an array.
     *
     * @see Copied from \Drupal\Component\Utility\NestedArray::setValue()
     *
     * @param array<string, mixed> &$array
     * @param string[] $parents
     */
    public static function setNestedArrayValue(array &$array, array $parents, mixed $value, bool $force = false): void
    {
        $ref = &$array;
        foreach ($parents as $parent) {
            // PHP auto-creates container arrays and NULL entries without error if $ref
            // is NULL, but throws an error if $ref is set, but not an array.
            if ($force && isset($ref) && !is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$parent];
        }
        $ref = $value;
    }
}
