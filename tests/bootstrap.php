<?php
/**
 * @file
 * A script containing any set-up steps required for PHPUnit testing.
 */

require __DIR__ . '/../vendor/autoload.php';

putenv(CLI_ENV_PREFIX . 'DRUSH=' . CLI_ROOT . '/vendor/bin/drush');
