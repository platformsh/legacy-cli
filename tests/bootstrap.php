<?php
/**
 * @file
 * A script containing any set-up steps required for PHPUnit testing.
 */

require __DIR__ . '/../vendor/autoload.php';

$env_prefix = (new \Platformsh\Cli\Service\Config())->get('application.env_prefix');
if (!getenv($env_prefix . 'DRUSH')) {
    putenv($env_prefix . 'DRUSH=' . CLI_ROOT . '/vendor/bin/drush');
}
