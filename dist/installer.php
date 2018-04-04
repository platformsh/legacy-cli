<?php
/**
 * @file
 * Platform.sh CLI installer.
 */

define('CLI_UPDATE_MANIFEST_URL', 'https://platform.sh/cli/manifest.json');
define('CLI_CONFIG_DIR', '.platformsh');
define('CLI_EXECUTABLE', 'platform');
define('CLI_NAME', 'Platform.sh CLI');
define('CLI_PHAR', CLI_EXECUTABLE . '.phar');

set_error_handler(
    function ($code, $message) {
        if ($code & error_reporting()) {
            echo PHP_EOL . "Error ($code): $message" . PHP_EOL;
            exit(1);
        }
    }
);

output(CLI_NAME . " installer", 'heading');

// Run environment checks.
output(PHP_EOL . "Environment check", 'heading');

// Check the PHP version.
// @todo load this requirement for the version under consideration
$min_php = '5.5.9';
check(
    sprintf('The PHP version is supported: %s.', PHP_VERSION),
    sprintf('The PHP version is %s, but %s or greater is required.', PHP_VERSION, $min_php),
    function () use ($min_php) {
        return version_compare(PHP_VERSION, $min_php, '>=');
    }
);

// Check that the JSON extension is installed (needed in this script).
check(
    'The "json" PHP extension is installed.',
    'The "json" PHP extension is required.',
    function () {
        return extension_loaded('json');
    }
);

// Check that the Phar extension is installed (needed in this script).
check(
    'The "phar" PHP extension is installed.',
    'The "phar" PHP extension is required.',
    function () {
        return extension_loaded('phar');
    }
);

// Check that Git is installed.
check(
    'Git is installed.',
    'Warning: Git will be needed.',
    function () {
        exec('command -v git', $output, $return_var);
        return $return_var === 0;
    },
    false
);

$required_extensions = [
    'mbstring',
    'openssl',
    'pcre',
];
foreach ($required_extensions as $extension) {
    check(
        'The "' . $extension . '" PHP extension is installed.',
        'Warning: the "' . $extension . '" PHP extension will be needed.',
        function () use ($extension) {
            return extension_loaded($extension);
        },
        false
    );
}

// Check Suhosin restrictions.
if (extension_loaded('suhosin')) {
    check(
        'The "phar" stream wrapper is allowed by Suhosin.',
        'The "phar" stream wrapper is blocked by Suhosin.',
        function () {
            $white = ini_get('suhosin.executor.include.whitelist');
            $black = ini_get('suhosin.executor.include.blacklist');

            if ((false === stripos($white, 'phar'))
                || (false !== stripos($black, 'phar'))
            ) {
                return false;
            }

            return true;
        }
    );
}

// Check whether PHP can open files via URLs.
check(
    'The "allow_url_fopen" setting is on.',
    'The "allow_url_fopen" setting is off; it must be on.',
    function () {
        return (true == ini_get('allow_url_fopen'));
    }
);

// Check a troublesome APC setting.
check(
    'The "apc.enable_cli" setting is off.',
    'Warning: the "apc.enable_cli" is on; this may cause problems with Phar files.',
    function () {
        return (false == ini_get('apc.enable_cli'));
    },
    false
);

// The necessary checks have passed. Start downloading the right version.
output(PHP_EOL . 'Download', 'heading');

output('  Finding the latest version...');
$manifest = file_get_contents(CLI_UPDATE_MANIFEST_URL);
if ($manifest === false) {
    output('  Failed to download manifest file: ' . CLI_UPDATE_MANIFEST_URL, 'error');
    exit(1);
}

$manifest = json_decode($manifest);
if ($manifest === null) {
    output('  Failed to decode manifest file: ' . CLI_UPDATE_MANIFEST_URL, 'error');
    exit(1);
}

// Find the item with the greatest version number in the manifest.
/** @var stdClass|null $latest */
$latest = null;
foreach ($manifest as $item) {
    if (empty($latest) || version_compare($item->version, $latest->version, '>')) {
        if (!strpos($item->version, '-') || strpos($item->version, '-stable')) {
            $latest = $item;
        }
    }
}
if (empty($latest)) {
    output('  No download was found.', 'error');
    exit(1);
}

output("  Downloading version {$latest->version}...");
if (!file_put_contents(CLI_PHAR, file_get_contents($latest->url))) {
    output('  The download failed.', 'error');
}

$pharPath = realpath(CLI_PHAR) ?: CLI_PHAR;

output('  Checking file integrity...');
if ($latest->sha256 !== hash_file('sha256', $pharPath)) {
    unlink(CLI_PHAR);
    output('  The download was corrupted.', 'error');
    exit(1);
}

output('  Checking that the file is a valid Phar (PHP Archive)...');

try {
    new Phar($pharPath);
} catch (Exception $e) {
    output('  The file is not a valid Phar archive.', 'error');

    throw $e;
}

output(PHP_EOL . 'Install', 'heading');

output('  Making the Phar executable...');
if (!chmod($pharPath, 0755)) {
    output('  Failed to make the Phar executable: ' . $pharPath, 'warning');
}

if ($homeDir = getHomeDirectory()) {
    output('  Moving the Phar to your home directory...');
    $binDir = $homeDir . '/' . CLI_CONFIG_DIR . '/bin';
    if (!is_dir($binDir) && !mkdir($binDir, 0700, true)) {
        output('  Failed to create directory: ' . $binDir, 'error');
    } elseif (!rename($pharPath, $binDir . '/' . CLI_EXECUTABLE)) {
        output('  Failed to move the Phar to: ' . $binDir . '/' . CLI_EXECUTABLE, 'error');
    } else {
        $pharPath = $binDir . '/' . CLI_EXECUTABLE;
        output('  Successfully moved the Phar to: ' . $pharPath);
    }
}

output(PHP_EOL . '  Running self:install command...');
putenv('CLICOLOR_FORCE=' . is_ansi() ? '1' : '0');
exec('php ' . $pharPath . ' self:install --yes 2>&1', $output, $return_var);
output(preg_replace('/^/m', '  ', implode(PHP_EOL, $output)));
if ($return_var === 0) {
    output(PHP_EOL . '  The installation completed successfully.');
} else {
    exit($return_var);
}

/**
 * Checks a condition, outputs a message, and exits if failed.
 *
 * @param string   $success   The success message.
 * @param string   $failure   The failure message.
 * @param callable $condition The condition to check.
 * @param bool     $exit      Whether to exit on failure.
 */
function check($success, $failure, $condition, $exit = true)
{
    if ($condition()) {
        output('  [*] ' . $success, 'success');
    } else {
        output('  [ ] ' . $failure, $exit ? 'error' : 'warning');

        if ($exit) {
            exit(1);
        }
    }
}

/**
 * Outputs formatted text.
 *
 * @param string $text
 * @param string $color
 * @param bool   $newLine
 */
function output($text, $color = null, $newLine = true)
{
    static $ansi;
    if (!isset($ansi)) {
        $ansi = is_ansi();
    }

    static $styles = array(
        'success' => "\033[0;32m%s\033[0m",
        'error' => "\033[31;31m%s\033[0m",
        'info' => "\033[33m%s\033[39m",
        'warning' => "\033[33m%s\033[39m",
        'heading' => "\033[1;33m%s\033[22;39m",
    );

    $format = '%s';

    if (isset($styles[$color]) && $ansi) {
        $format = $styles[$color];
    }

    if ($newLine) {
        $format .= PHP_EOL;
    }

    printf($format, $text);
}

/**
 * Returns whether to use ANSI escape sequences.
 *
 * @return bool
 */
function is_ansi()
{
    global $argv;
    if (!empty($argv)) {
        if (in_array('--no-ansi', $argv)) {
            return false;
        } elseif (in_array('--ansi', $argv)) {
            return true;
        }
    }

    // On Windows, default to no ANSI, except in ANSICON and ConEmu.
    // Everywhere else, default to ANSI if stdout is a terminal.
    return (DIRECTORY_SEPARATOR == '\\')
        ? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
        : (function_exists('posix_isatty') && posix_isatty(1));
}

/**
 * Finds the user's home directory.
 *
 * @return string|false
 *   The user's home directory as an absolute path, or false on failure.
 */
function getHomeDirectory()
{
    if ($home = getenv('HOME')) {
        return $home;
    } elseif ($userProfile = getenv('USERPROFILE')) {
        return $userProfile;
    } elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
        return $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
    }

    return false;
}
