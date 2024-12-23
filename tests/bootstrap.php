<?php

declare(strict_types=1);

/**
 * @file
 * A script containing any set-up steps required for PHPUnit testing.
 */

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

putenv('PLATFORMSH_CLI_TOKEN=');
