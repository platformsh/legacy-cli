<?php
/**
 * @file
 * A script containing any set-up steps required for PHPUnit testing.
 */

require __DIR__ . '/../vendor/autoload.php';

putenv((new \Platformsh\Cli\CliConfig())->get('application.env_prefix') . 'DRUSH=' . CLI_ROOT . '/vendor/bin/drush');
