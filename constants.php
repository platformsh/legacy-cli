<?php
/**
 * @file
 * Constants used throughout the CLI.
 */

// Root of this CLI instance.
define('CLI_ROOT', __DIR__);

// The name of the wonderful service with which this CLI connects.
define('CLI_CLOUD_SERVICE', 'Magento Cloud');

// The (human-readable) name of this CLI program.
define('CLI_NAME', 'Magento Cloud CLI');

// The version.
define('CLI_VERSION', '1.0.0');

// Other metadata about the program (also set in box.json and composer.json
// files and described in the README.md).
define('CLI_EXECUTABLE', 'magento-cloud');
define('CLI_PHAR', CLI_EXECUTABLE . '.phar');
define('CLI_PACKAGE_NAME', null);
define('CLI_SOURCE_URL', null);
define('CLI_INSTALLER_URL', 'https://accounts.magento.cloud/cli/installer');
define('CLI_UPDATE_MANIFEST_URL', 'https://accounts.magento.cloud/cli/manifest.json');

// Name of the user's configuration directory for the CLI (inside $HOME).
define('CLI_CONFIG_DIR', '.magento-cloud');

// The prefix for local environment variables (also described in README.md).
define('CLI_ENV_PREFIX', 'MAGENTO_CLOUD_CLI_');

// The prefix for environment variables in the remote environments.
define('CLI_REMOTE_ENV_PREFIX', 'MAGENTO_CLOUD_');

// Name of the Git remote used to detect projects.
define('CLI_GIT_REMOTE_NAME', 'magento');

// Domain used to detect projects from Git remote URLs.
define('CLI_PROJECT_GIT_DOMAIN', 'magento.cloud');

// Domain used to detect projects from API URLs.
define('CLI_PROJECT_API_DOMAIN', 'magento.cloud');

// The base URL of the documentation (no trailing slash).
define('CLI_SERVICE_DOCS_URL', 'http://devdocs.magento.com/');

// The URL of the Accounts website (no trailing slash).
define('CLI_SERVICE_ACCOUNTS_URL', 'https://accounts.magento.cloud');

// The base URL of the Accounts API (with a trailing slash). This can be
// overridden by the environment variable "MAGENTO_CLOUD_CLI_ACCOUNTS_API".
define('CLI_SERVICE_ACCOUNTS_API_URL', CLI_SERVICE_ACCOUNTS_URL . '/api/v1/');

// The OAuth 2.0 client ID used for authentication.
define('CLI_OAUTH_CLIENT_ID', 'magento-cloud-cli');

// Files inside the project repository that affect the build.
define('CLI_APP_CONFIG_FILE', '.magento.app.yaml');
define('CLI_PROJECT_CONFIG_DIR', '.magento');

// Files inside the project repository where CLI-specific information is saved.
define('CLI_LOCAL_DIR', '.magento-cloud/local');
define('CLI_LOCAL_ARCHIVE_DIR', CLI_LOCAL_DIR . '/build-archives');
define('CLI_LOCAL_BUILD_DIR', CLI_LOCAL_DIR . '/builds');
define('CLI_LOCAL_PROJECT_CONFIG', CLI_LOCAL_DIR . '/project.yaml');
define('CLI_LOCAL_PROJECT_CONFIG_LEGACY', '.magento-project');
define('CLI_LOCAL_SHARED_DIR', CLI_LOCAL_DIR . '/shared');
define('CLI_LOCAL_WEB_ROOT', '_www');
