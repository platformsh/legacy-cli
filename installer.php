<?php
/**
 * @file
 * Platform.sh CLI installer.
 */

define('MANIFEST_URL', 'https://platform.sh/cli/manifest.json');

$n = PHP_EOL;

set_error_handler(
  function ($code, $message) use ($n) {
      if ($code & error_reporting()) {
          echo "$n{$n}Error: $message$n$n";
          exit(1);
      }
  }
);

if (in_array('--no-ansi', $argv)) {
    define('USE_ANSI', false);
} elseif (in_array('--ansi', $argv)) {
    define('USE_ANSI', true);
} else {
    // On Windows, default to no ANSI, except in ANSICON and ConEmu.
    // Everywhere else, default to ANSI if stdout is a terminal.
    define('USE_ANSI',
    (DIRECTORY_SEPARATOR == '\\')
      ? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
      : (function_exists('posix_isatty') && posix_isatty(1))
    );
}

out("Platform.sh CLI installer", 'info');
out(PHP_EOL . "Environment check", 'info');

// check version
check(
  'You have a supported version of PHP (>= 5.4.10).',
  'You need PHP 5.4.10 or greater.',
  function () {
      return version_compare(PHP_VERSION, '5.4.10', '>=');
  }
);

// check openssl extension
check(
  'You have the "openssl" extension installed.',
  'Notice: The "openssl" extension will be needed.',
  function () {
      return extension_loaded('openssl');
  },
  false
);

// check curl extension
check(
  'You have the "curl" extension installed.',
  'Notice: The "curl" extension will be needed to use the Platform.sh API.',
  function () {
      return extension_loaded('curl');
  },
  false
);

// check suhosin setting
if (extension_loaded('suhosin')) {
    check(
      'The "phar" stream wrapper is allowed by suhosin.',
      'The "phar" stream wrapper is blocked by suhosin.',
      function () {
          $white = ini_get('suhosin.executor.include.whitelist');
          $black = ini_get('suhosin.executor.include.blacklist');

          if ((false === stripos($white, 'phar'))
            || (false !== stripos($black, 'phar'))) {
              return false;
          }

          return true;
      }
    );
}

// check allow url open setting
check(
  'The "allow_url_fopen" setting is on.',
  'The "allow_url_fopen" setting needs to be on.',
  function () {
      return (true == ini_get('allow_url_fopen'));
  }
);

// check apc cli caching
check(
  'The "apc.enable_cli" setting is off.',
  'Notice: The "apc.enable_cli" is on and may cause problems with Phars.',
  function () {
      return (false == ini_get('apc.enable_cli'));
  },
  false
);

out(PHP_EOL . "Download", 'info');

out("  Finding the latest version...");

$manifest = file_get_contents(MANIFEST_URL);
if ($manifest === false) {
    out(PHP_EOL . "Failed to download manifest file: " . MANIFEST_URL . $n, 'error');
    exit(1);
}

$manifest = json_decode($manifest);
if ($manifest === null) {
    out(PHP_EOL . "Failed to decode manifest file: " . MANIFEST_URL . $n, 'error');
    exit(1);
}

$current = null;

foreach ($manifest as $item) {
    $item->version = ltrim($item->version, 'v');
    if ($current
      && (version_compare($item->version, $current->version, '>'))) {
        $current = $item;
    }
}

if (!$item) {
    out(PHP_EOL . "No download was found.$n", 'error');
    exit(1);
}

out("  Downloading version {$item->version}...");

file_put_contents($item->name, file_get_contents($item->url));

out("  Checking file checksum...");

if ($item->sha1 !== sha1_file($item->name)) {
    unlink($item->name);
    out("  The download was corrupted.$n", 'error');
    exit(1);
}

out("  Validating Phar...");

try {
    new Phar($item->name);
} catch (Exception $e) {
    out("  The Phar is not valid.$n$n", 'error');

    throw $e;
}

out("  Making the file executable...");

@chmod($item->name, 0755);

out(PHP_EOL . "The Platform.sh CLI v{$item->version} was installed successfully!", 'success');
out(PHP_EOL . "Use it as a local file:", 'info');
out("  php platform.phar");
out(PHP_EOL . "Or install it globally on your system:", 'info');
out("  mv platform.phar /usr/local/bin/platform");
out("  platform");

/**
 * Checks a condition, outputs a message, and exits if failed.
 *
 * @param string   $success   The success message.
 * @param string   $failure   The failure message.
 * @param callable $condition The condition to check.
 * @param boolean  $exit      Exit on failure?
 */
function check($success, $failure, $condition, $exit = true)
{
    global $n;

    if ($condition()) {
        out ('  [*] ' . $success, 'success');
    } else {
        out('  [ ] ' . $failure . $n, 'error');

        if ($exit) {
            exit(1);
        }
    }
}

/**
 * colorize output
 */
function out($text, $color = null, $newLine = true)
{
    $styles = array(
      'success' => "\033[0;32m%s\033[0m",
      'error' => "\033[31;31m%s\033[0m",
      'info' => "\033[33;33m%s\033[0m"
    );

    $format = '%s';

    if (isset($styles[$color]) && USE_ANSI) {
        $format = $styles[$color];
    }

    if ($newLine) {
        $format .= PHP_EOL;
    }

    printf($format, $text);
}
