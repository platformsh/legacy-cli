<?php
/**
 * @file
 * Router for the PHP built-in web server.
 */

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
          4
        );
    });

    // Workaround for https://bugs.php.net/64566
    if (ini_get('auto_prepend_file') && !in_array(realpath(ini_get('auto_prepend_file')), get_included_files(), true)) {
        require ini_get('auto_prepend_file');
    }

    chdir(dirname($filename));

    $_SERVER['SCRIPT_FILENAME'] = $filename;

    require $filename;
};

// Get the application configuration from the environment.
if (!isset($_ENV[$variables_prefix . 'APPLICATION'])) {
    http_response_code(500);
    error_log('Environment variable not found: ' . $variables_prefix . 'APPLICATION', 4);
    exit;
}
$app = json_decode(base64_decode($_ENV[$variables_prefix . 'APPLICATION']), true);

// Support for Drupal features.
// See: https://www.drupal.org/node/1543858
if (!empty($app['is_drupal'])) {
    // The Drupal 7 request_path() function has a strange check which causes
    // the path to be treated as the front page if ($path == basename($_SERVER['PHP_SELF']).
    // Setting $_GET['q'] manually works around this.
    $url = parse_url($_SERVER['REQUEST_URI']);
    $_GET['q'] = $_REQUEST['q'] = ltrim($url['path'], '/');
}

// Determine which passthru script, if any, should be used.
$passthru = null;
if (!empty($app['web']['passthru'])) {
    $passthru = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($app['web']['passthru'], '/');
    if (!file_exists($passthru)) {
        http_response_code(500);
        error_log(sprintf('Passthru file not found: %s', $passthru), 4);
        exit;
    }
}

// Find what file the user is asking for.
$requested_file = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . ltrim($_SERVER['SCRIPT_NAME'], '/');

// The index file for a directory is being requested (the PHP built-in web
// server asks for 'index.php' if it exists).
if (basename($requested_file) === 'index.php' || is_dir($requested_file)) {
    $index_files = ['index.php'];
    if (isset($app['web']['index_files'])) {
        $index_files = (array) $app['web']['index_files'];
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
            error_log(sprintf('No index file found in directory: %s', $directory), 4);
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

// Find the relative path to the requested file, for processing the whitelist or
// blacklist.
$relative_path = ltrim(substr($requested_file, strlen($_SERVER['DOCUMENT_ROOT'])), DIRECTORY_SEPARATOR);

// Process the blacklist. If the path matches, serve the passthru or a 403.
if (!empty($app['web']['blacklist'])) {
    $pattern = '#' . implode('|', (array) $app['web']['blacklist']) . '#';
    if (preg_match($pattern, $relative_path)) {
        if ($passthru) {
            $requested_file = $passthru;
        }
        else {
            http_response_code(403);
            echo "Access denied.";
            error_log(sprintf('Access denied. File in blacklist: %s', $relative_path), 4);
            exit;
        }
    }
}

// Run the file, if it's PHP.
if (preg_match('/\.php$/', $requested_file)) {
    $run_php($requested_file);
    exit;
}

// Process the whitelist. If the path doesn't match, serve a 403.
$whitelist = isset($app['web']['whitelist']) ? (array) $app['web']['whitelist'] : [
  # CSS and Javascript.
  '\.css$',
  '\.js$',

  # image/* types.
  '\.gif$',
  '\.jpe?g$',
  '\.png$',
  '\.tiff?$',
  '\.wbmp$',
  '\.ico$',
  '\.jng$',
  '\.bmp$',
  '\.svgz?$',

  # audio/* types.
  '\.midi?$',
  '\.mpe?ga$',
  '\.mp2$',
  '\.mp3$',
  '\.m4a$',
  '\.ra$',
  '\.weba$',

  # video/* types.
  '\.3gpp?$',
  '\.mp4$',
  '\.mpe?g$',
  '\.mpe$',
  '\.ogv$',
  '\.mov$',
  '\.webm$',
  '\.flv$',
  '\.mng$',
  '\.asx$',
  '\.asf$',
  '\.wmv$',
  '\.avi$',

  # application/ogg.
  '\.ogx$',

  # application/x-shockwave-flash.
  '\.swf$',

  # application/java-archive.
  '\.jar$',

  # fonts types.
  '\.ttf$',
  '\.eot$',
  '\.woff$',
  '\.otf$',

  # robots.txt.
  '/robots\.txt$',
];
$pattern = '#' . implode('|', $whitelist) . '#';
if (!preg_match($pattern, $relative_path)) {
    http_response_code(403);
    error_log(sprintf('Access denied. File not in whitelist: %s', $relative_path), 4);
    exit;
}

// Returning false causes PHP to serve the requested file as-is, with the
// correct MIME type, etc.
return false;
