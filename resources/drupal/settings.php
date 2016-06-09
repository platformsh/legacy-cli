<?php
/**
 * A default settings.php file for Drupal 7.
 */

/**
 * Deny access to the update.php script.
 */
$update_free_access = FALSE;

/**
 * Salt for one-time login links and cancel links, form tokens, etc.
 *
 * If this variable is left empty, Platform.sh will attempt to set it
 * automatically based on a project-specific secret. On non-Platform.sh
 * environments, Drupal will use a hash of the serialized database credentials
 * as a fallback salt.
 */
// $drupal_hash_salt = '';

// Local settings. These are required for Platform.sh, when using the `drupal`
// build flavor.
$local_settings = dirname(__FILE__) . '/settings.local.php';
if (file_exists($local_settings)) {
  include $local_settings;
}
