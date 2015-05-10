<?php
/**
 * @file
 * A script containing any set-up steps required for PHPUnit testing.
 */

require __DIR__ . '/../vendor/autoload.php';

define('CLI_ROOT', dirname(__DIR__));

putenv('PLATFORM_CLI_DRUSH=' . CLI_ROOT . '/vendor/bin/drush');
