<?php
/**
 * @file
 * Constants used throughout the CLI.
 */

// Root of this CLI instance.
define('CLI_ROOT', __DIR__);

// The name of the wonderful service with which this CLI connects.
define('CLI_CLOUD_SERVICE', 'Platform.sh');

// The (human-readable) name of this CLI program.
define('CLI_NAME', 'Platform.sh CLI');

// The version.
define('CLI_VERSION', '3.0.3');

// Other metadata about the program (also set in box.json and composer.json
// files and described in the README.md).
define('CLI_EXECUTABLE', 'platform');
define('CLI_PHAR', CLI_EXECUTABLE . '.phar');
define('CLI_PACKAGE_NAME', 'platformsh/cli');
define('CLI_SOURCE_URL', 'https://github.com/platformsh/platformsh-cli');
define('CLI_INSTALLER_URL', 'https://platform.sh/cli/installer');
define('CLI_UPDATE_MANIFEST_URL', 'https://platform.sh/cli/manifest.json');

// Name of the user's configuration directory for the CLI (inside $HOME).
define('CLI_CONFIG_DIR', '.platformsh');

// The prefix for local environment variables (also described in README.md).
define('CLI_ENV_PREFIX', 'PLATFORMSH_CLI_');

// The prefix for environment variables in the remote environments.
define('CLI_REMOTE_ENV_PREFIX', 'PLATFORM_');

// Name of the Git remote used to detect projects.
define('CLI_GIT_REMOTE_NAME', 'platform');

// Domain used to detect projects from Git remote URLs.
define('CLI_PROJECT_GIT_DOMAIN', 'platform.sh');

// Domain used to detect projects from API URLs.
define('CLI_PROJECT_API_DOMAIN', 'platform.sh');

// The base URL of the documentation (no trailing slash).
define('CLI_SERVICE_DOCS_URL', 'https://docs.platform.sh');

// The URL of the Accounts website (no trailing slash).
define('CLI_SERVICE_ACCOUNTS_URL', 'https://accounts.platform.sh');

// The base URL of the Accounts API (with a trailing slash). This can be
// overridden by the environment variable "PLATFORMSH_CLI_ACCOUNTS_API".
define('CLI_SERVICE_ACCOUNTS_API_URL', CLI_SERVICE_ACCOUNTS_URL . '/api/platform/');

// The OAuth 2.0 client ID used for authentication.
define('CLI_OAUTH_CLIENT_ID', 'platform-cli');

// Files inside the project repository that affect the build.
define('CLI_APP_CONFIG_FILE', '.platform.app.yaml');
define('CLI_PROJECT_CONFIG_DIR', '.platform');

// Files inside the project repository where CLI-specific information is saved.
define('CLI_LOCAL_DIR', '.platform/local');
define('CLI_LOCAL_ARCHIVE_DIR', CLI_LOCAL_DIR . '/build-archives');
define('CLI_LOCAL_BUILD_DIR', CLI_LOCAL_DIR . '/builds');
define('CLI_LOCAL_PROJECT_CONFIG', CLI_LOCAL_DIR . '/project.yaml');
define('CLI_LOCAL_PROJECT_CONFIG_LEGACY', '.platform-project');
define('CLI_LOCAL_SHARED_DIR', CLI_LOCAL_DIR . '/shared');
define('CLI_LOCAL_WEB_ROOT', '_www');
