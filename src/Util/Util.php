<?php

namespace Platformsh\Cli\Util;

class Util
{
    /**
     * Get a nested value in an array.
     *
     * @see Copied from \Drupal\Component\Utility\NestedArray::getValue()
     *
     * @param array $array
     * @param array $parents
     * @param bool  $key_exists
     *
     * @return mixed
     */
    public static function &getNestedArrayValue(array &$array, array $parents, &$key_exists = NULL)
    {
        $ref = &$array;
        foreach ($parents as $parent) {
            if (is_array($ref) && array_key_exists($parent, $ref)) {
                $ref = &$ref[$parent];
            }
            else {
                $key_exists = FALSE;
                $null = NULL;
                return $null;
            }
        }
        $key_exists = TRUE;

        return $ref;
    }
}
