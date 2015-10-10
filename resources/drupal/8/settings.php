<?php

/**
 * Access control for update.php script.
 */
$settings['update_free_access'] = FALSE;

// Default PHP settings.
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 200000);
ini_set('session.cookie_lifetime', 2000000);
ini_set('pcre.backtrack_limit', 200000);
ini_set('pcre.recursion_limit', 200000);

// Local settings. These are required for Platform.sh.
$local_settings = dirname(__FILE__) . '/settings.local.php';
if (file_exists($local_settings)) {
  include $local_settings;
}
