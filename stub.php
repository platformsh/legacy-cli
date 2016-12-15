#!/usr/bin/env php -d variables_order=es
<?php
/**
 * @file
 * Platform.sh CLI Phar stub.
 */

if (class_exists('Phar')) {
    Phar::mapPhar('default.phar');
    require 'phar://' . __FILE__ . '/bin/platform';
}

__HALT_COMPILER(); ?>
