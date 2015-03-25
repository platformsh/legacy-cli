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

// Services definition file.
$settings['container_yamls'][] = __DIR__ . '/services.yml';

// Location of the site configuration files.
$config_directories = array();
