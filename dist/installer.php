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

// Check that the openssl extension exists.
check(
    'The "openssl" PHP extension is installed.',
    'Warning: the "openssl" PHP extension will be needed.',
    function () {
        return extension_loaded('openssl');
    },
    false
);

// Check that the curl extension exists.
check(
    'The "curl" PHP extension is installed.',
    'Warning: the "curl" PHP extension will be needed.',
    function () {
        return extension_loaded('curl');
    },
    false
);

// Check that the ctype extension exists.
check(
    'The "ctype" PHP extension is installed.',
    'Warning: the "ctype" PHP extension will be needed.',
    function () {
        return extension_loaded('ctype');
    },
    false
);

// Check that the mbstring and/or iconv extensions exist.
check(
    'The "mbstring" and/or "iconv" PHP extensions are installed.',
    'Warning: the "mbstring" and/or "iconv" PHP extensions will be needed.',
    function () {
        return extension_loaded('mbstring') || extension_loaded('iconv');
    },
    false
);

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
output(PHP_EOL . "Download", 'heading');

output("  Finding the latest version...");
$manifest = file_get_contents(CLI_UPDATE_MANIFEST_URL);
if ($manifest === false) {
    output("  Failed to download manifest file: " . CLI_UPDATE_MANIFEST_URL, 'error');
    exit(1);
}

$manifest = json_decode($manifest);
if ($manifest === null) {
    output("  Failed to decode manifest file: " . CLI_UPDATE_MANIFEST_URL, 'error');
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
    output("  No download was found.", 'error');
    exit(1);
}

output("  Downloading version {$latest->version}...");
if (!file_put_contents(CLI_PHAR, file_get_contents($latest->url))) {
    output("  The download failed.", 'error');
}

output("  Checking file integrity...");
if ($latest->sha1 !== sha1_file(CLI_PHAR)) {
    unlink(CLI_PHAR);
    output("  The download was corrupted.", 'error');
    exit(1);
}

output("  Checking that the file is a valid Phar (PHP Archive)...");

try {
    new Phar(CLI_PHAR);
} catch (Exception $e) {
    output("  The file is not a valid Phar archive.", 'error');

    throw $e;
}

output("  Making the Phar executable...");
chmod(CLI_PHAR, 0755);

// Attempt automatic configuration of the shell (including the PATH).
$installedInHomeDir = false;
$configured = false;
if ($home = getHomeDirectory()) {
    $configDir = $home . '/' . CLI_CONFIG_DIR;

    if (!file_exists($configDir . '/bin')) {
        mkdir($configDir . '/bin', 0700, true);
    }

    // Extract the shell-config.rc file out of the Phar, so that it can be included
    // in the user's shell configuration. N.B. reading from a Phar only works
    // while it still has the '.phar' extension.
    output('  Extracting the shell configuration file...');
    $rcDestination = $configDir . '/shell-config.rc';
    $rcSource = 'phar://' . CLI_PHAR . '/shell-config.rc';
    if (($rcContents = file_get_contents($rcSource)) === false) {
        output(sprintf('  Failed to read file: %s', $rcSource), 'warning');
    }
    elseif (file_put_contents($rcDestination, $rcContents) === false) {
        output(sprintf('  Failed to write file: %s', $rcDestination), 'warning');
    }

    output("  Installing the Phar into your home directory...");
    if (rename(CLI_PHAR, $configDir . '/bin/' . CLI_EXECUTABLE)) {
        $installedInHomeDir = true;
        output(
            "  The Phar was saved to: " . $configDir . '/bin/' . CLI_EXECUTABLE
        );
    } else {
        output("  Failed to move the Phar.", 'warning');
    }

    // Configure the user's shell to add to the $PATH and to source the
    // shell-config.rc file.
    if ($shellConfigFile = findShellConfigFile($home)) {
        output("  Configuring the shell...");
        $configured = true;
        $currentShellConfig = file_get_contents($shellConfigFile);
        if ($currentShellConfig === false) {
            $currentShellConfig = '';
        }

        // Backwards compatibility for the old 'platform.rc'.
        // @todo remove any time after about late 2016.
        $oldRcLocation = str_replace('/shell-config.rc', '/platform.rc', $rcDestination);
        if (file_exists($oldRcLocation)) {
            @unlink($oldRcLocation);
        }
        if (strpos($currentShellConfig, $oldRcLocation) !== false) {
            $currentShellConfig = str_replace($oldRcLocation, $rcDestination, $currentShellConfig);
            if (!file_put_contents($shellConfigFile, $currentShellConfig)) {
                output("  Failed to configure the shell automatically.", 'warning');
            }
        }
        // End backwards compatibility section.

        if (strpos($currentShellConfig, $configDir . "/bin") === false) {
            $currentShellConfig .= PHP_EOL . PHP_EOL
                . "# Automatically added by " . CLI_NAME . " installer" . PHP_EOL
                . "export PATH=\"$configDir/bin:\$PATH\"" . PHP_EOL
                . '. ' . escapeshellarg($rcDestination) . " 2>/dev/null" . PHP_EOL;
            if (!file_put_contents($shellConfigFile, $currentShellConfig)) {
                $configured = false;
                output("  Failed to configure the shell automatically.", 'warning');
            }
        }
    }
}

output(
    PHP_EOL . "The " . CLI_NAME . " v{$latest->version} was installed successfully!",
    'success'
);

// Tell the user what to do if the automatic installation succeeded.
if ($installedInHomeDir) {
    if ($configured) {
        output(PHP_EOL . "To get started, run:", 'info');
        $toSource = getcwd() === $home ? str_replace(getcwd() . '/', '', $shellConfigFile) : $shellConfigFile;
        output('  source ' . $toSource);
        output('  ' . CLI_EXECUTABLE);
    } else {
        output(PHP_EOL . "Add this to your shell configuration file:", 'info');
        output('  export PATH="' . $home . '/' . CLI_CONFIG_DIR . '/bin:$PATH"');
        output('  . ' . escapeshellarg($rcDestination) . ' 2>/dev/null');
        output(PHP_EOL . "Start a new shell, and then you can run '" . CLI_EXECUTABLE . "'", 'info');
    }
} else {
    // Otherwise, the user still has a Phar file.
    output(PHP_EOL . "Use it as a local file:", 'info');
    output('  php ' . CLI_PHAR);

    output(PHP_EOL . "Or install it globally on your system:", 'info');
    output('  mv ' . CLI_PHAR . ' /usr/local/bin/' . CLI_EXECUTABLE);
    output('  ' . CLI_EXECUTABLE);
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
 * Finds a shell configuration file for the user.
 *
 * @param string $home
 *   The user's home directory.
 *
 * @return string|false
 *   The absolute path to an existing shell config file, or false on failure.
 */
function findShellConfigFile($home)
{
    $candidates = array(
        $home . '/.bash_profile',
        $home . '/.bashrc',
    );
    $shell = str_replace('/bin/', '', getenv('SHELL'));
    if ($shell === 'zsh') {
        array_unshift($candidates, $home . '/.zshrc');
        array_unshift($candidates, $home . '/.zprofile');
    }
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return false;
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
