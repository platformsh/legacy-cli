<?php

$local_settings = __DIR__ . '/settings.local.php';
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
