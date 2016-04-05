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

    /**
     * Set a nested value in an array.
     *
     * @see Copied from \Drupal\Component\Utility\NestedArray::setValue()
     *
     * @param array &$array
     * @param array $parents
     * @param mixed $value
     * @param bool  $force
     */
    public static function setNestedArrayValue(array &$array, array $parents, $value, $force = FALSE) {
        $ref = &$array;
        foreach ($parents as $parent) {
            // PHP auto-creates container arrays and NULL entries without error if $ref
            // is NULL, but throws an error if $ref is set, but not an array.
            if ($force && isset($ref) && !is_array($ref)) {
                $ref = array();
            }
            $ref = &$ref[$parent];
        }
        $ref = $value;
    }

}
