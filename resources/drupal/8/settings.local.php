<?php

// Database configuration.
$databases['default']['default'] = array(
  'driver' => 'mysql',
  'host' => 'localhost',
  'username' => '',
  'password' => '',
  'database' => '',
  'prefix' => '',
);

// Salt for one-time login links, cancel links, form tokens, etc.
// You should modify this.
$settings['hash_salt'] = '4946c1912834b8477cc70af309a2c30dcfc24c2103c724ff30bf13b4c10efd82';

$config_directories = array();

// Custom configuration directories.
// Unfortunately, this appears to prevent installs of Drupal 8.0.0-beta9.
//
// $config_directories = array(
//   CONFIG_ACTIVE_DIRECTORY => '../../config/active',
//   CONFIG_STAGING_DIRECTORY => '../../config/staging',
// );
