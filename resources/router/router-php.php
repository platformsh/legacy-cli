<?php
/**
 * @file
 * Router for the PHP built-in web server.
 */

define('ERROR_LOG_TYPE_SAPI', 4);
$variables_prefix = isset($_ENV['_PLATFORM_VARIABLES_PREFIX']) ? $_ENV['_PLATFORM_VARIABLES_PREFIX'] : 'PLATFORM_';

// Define a callback for running a PHP file (usually the passthru script).
$run_php = function ($filename) {
    register_shutdown_function(function () {
        error_log(
            sprintf(
                '%s:%d [%d]: %s %s',
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['REMOTE_PORT'],
                http_response_code(),
                $_SERVER['REQUEST_METHOD'],
                $_SERVER['REQUEST_URI']
            ),
            ERROR_LOG_TYPE_SAPI
        );
    });

    // Workaround for https://bugs.php.net/64566
    if (ini_get('auto_prepend_file') && file_exists(ini_get('auto_prepend_file'))) {
        require_once ini_get('auto_prepend_file');
    }

    chdir(dirname($filename));

    $_SERVER['SCRIPT_FILENAME'] = $filename;

    require $filename;
};

$pregQuoteNginxPattern = function ($pattern) {
    return '#' . str_replace('#', '\\#', $pattern) . '#';
};

$is_php = function ($filename) {
    return preg_match('/\.php$/', $filename);
};

// Get the application configuration from the environment.
if (!isset($_ENV[$variables_prefix . 'APPLICATION'])) {
    http_response_code(500);
    error_log('Environment variable not found: ' . $variables_prefix . 'APPLICATION', ERROR_LOG_TYPE_SAPI);
    exit;
}
$app = json_decode(base64_decode($_ENV[$variables_prefix . 'APPLICATION']), true);

// Support for Drupal features.
// See: https://www.drupal.org/node/1543858
if (!empty($app['drupal_7_workaround'])) {
    // The Drupal 7 request_path() function has a strange check which causes
    // the path to be treated as the front page if ($path == basename($_SERVER['PHP_SELF']).
    // Setting $_GET['q'] manually works around this.
    $url = parse_url($_SERVER['REQUEST_URI']);
    $_GET['q'] = $_REQUEST['q'] = ltrim($url['path'], '/');
}

// Find the correct location.
$locations = isset($app['web']['locations']) ? $app['web']['locations'] : [];
$location = ['allow' => true];
$matchedLocation = '/';
foreach (array_keys($locations) as $path) {
    if (strpos($_SERVER['REQUEST_URI'], $path) === 0) {
        $matchedLocation = $path;
    } elseif (preg_match($pregQuoteNginxPattern($path), $_SERVER['REQUEST_URI'])) {
        $matchedLocation = $path;
    }
}
if (isset($app['web']['locations'][$matchedLocation])) {
    $location = $app['web']['locations'][$matchedLocation];
}

// Determine which passthru script, if any, should be used.
$passthru = null;
if (!empty($location['passthru'])) {
    $passthru = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($location['passthru'], '/');
    if (!file_exists($passthru)) {
        http_response_code(500);
        error_log(sprintf('Passthru file not found: %s', $passthru), ERROR_LOG_TYPE_SAPI);
        exit;
    }
}

// Find what file the user is asking for.
$requested_file = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($_SERVER['SCRIPT_NAME'], '/');

// The index file for a directory is being requested (the PHP built-in web
// server asks for 'index.php' if it exists).
if (basename($requested_file) === 'index.php' || is_dir($requested_file)) {
    $index_files = ['index.php'];
    if (isset($location['index'])) {
        $index_files = (array) $location['index'];
    }
    $directory = is_dir($requested_file)
      ? rtrim($requested_file, DIRECTORY_SEPARATOR)
      : dirname($requested_file);
    $index_found = false;
    foreach ($index_files as $filename) {
        if (is_file($directory . DIRECTORY_SEPARATOR . $filename)) {
            $requested_file = $directory . DIRECTORY_SEPARATOR . $filename;
            $index_found = true;
            break;
        }
    }
    if (!$index_found) {
        if (!$passthru) {
            http_response_code(500);
            error_log(sprintf('No index file found in directory: %s', $directory), ERROR_LOG_TYPE_SAPI);
            exit;
        }
        $requested_file = $passthru;
    }
}

// Pass on to PHP or the passthru file early.
if ($passthru && ($requested_file === $passthru || !file_exists($requested_file))) {
    $run_php($passthru);
    exit;
}

// Find the root-relative path to the requested file, for processing rules.
$relative_path = ltrim(substr($requested_file, strlen($_SERVER['DOCUMENT_ROOT'])), DIRECTORY_SEPARATOR);

// Process the static files rules.
$allow = isset($location['allow']) ? (bool) $location['allow'] : true;
if (!empty($location['rules'])) {
    foreach ($location['rules'] as $pattern => $rule) {
        if (preg_match($pregQuoteNginxPattern($pattern), $relative_path)) {
            if (isset($rule['allow'])) {
                $allow = (bool) $rule['allow'];
                if (!$allow) {
                    error_log(sprintf(
                        'Static file "%s" blocked by rule "%s"',
                        $relative_path,
                        $pattern
                    ), ERROR_LOG_TYPE_SAPI);
                }
            }
            break;
        }
    }
}

// Block scripts, if configured.
if ($is_php($requested_file) && isset($location['scripts']) && !$location['scripts']) {
    $allow = false;
}

// Handle files that are blocked.
if (!$allow) {
    if ($passthru) {
        $requested_file = $passthru;
    } else {
        http_response_code(403);
        echo "Access denied.";
        exit;
    }
}

// Run the file, if it's PHP.
if ($is_php($requested_file)) {
    $run_php($requested_file);
    exit;
}

// Returning false causes PHP to serve the requested file as-is, with the
// correct MIME type, etc.
return false;
