<?php /** @noinspection PhpFullyQualifiedNameUsageInspection */

/**
 * @file
 * Platform.sh CLI installer.
 *
 * This script will check requirements, download the CLI, move it into place,
 * and run the self:install command (to set up the PATH and autocompletion).
 *
 * Example (via curl):
 *      curl -sS https://platform.sh/cli/installer | php -- --min 3.43.0
 *
 * Example (via downloaded file):
 *      php installer.php -- --min 3.43.0
 *
 * Supported options:
 *      --alpha, --beta, --dev Install an unstable version.
 *      --no-ansi              Disable ANSI (no colors).
 *      --ansi                 Enable ANSI (e.g. for colors).
 *      --min VERSION          Min version to install.
 *      --max VERSION          Max version to install (not recommended).
 *      --manifest URL         A manifest JSON file URL (use for testing).
 *      --shell-type TYPE      The shell type for autocompletion (bash or zsh).
 *      --insecure             Disable TLS verification (not recommended).
 *
 * This file's syntax must support PHP 5.5.9 or higher.
 * It must not include any other files.
 */

namespace Platformsh\Cli\Installer;

// Check the minimum PHP version for this installer to run.
if (version_compare(PHP_VERSION, '5.5.9', '<')) {
    /** @noinspection PhpUnhandledExceptionInspection */
    throw new \Exception(sprintf('The PHP version is %s, but 5.5.9 or greater is required.', PHP_VERSION));
}

// Ensure we are running via the command line.
if (PHP_SAPI !== 'cli') {
    throw new \RuntimeException('This can only be run via command-line PHP.');
}

// Skip running the installer if we are including this from the CLI itself.
// This allows us to run tests on functions defined in this file.
$isCliInclude = defined('CLI_ROOT') && CLI_ROOT === dirname(__DIR__);
if (!$isCliInclude) {
    (new Installer())->run();
}

class Installer {
    private $envPrefix;
    private $manifestUrl;
    private $configDir;
    private $executable;
    private $cliName;
    private $userAgent;
    private $pharName;
    private $argv;

    public function __construct(array $args = []) {
        $this->argv = !empty($args) ? $args : $GLOBALS['argv'];

        // This config is automatically replaced by the self:build command,
        // to match the values in config.yaml.
        $config = /* START_CONFIG */array (
  'envPrefix' => 'PLATFORMSH_CLI_',
  'manifestUrl' => 'https://platform.sh/cli/manifest.json',
  'configDir' => '.platformsh',
  'executable' => 'platform',
  'cliName' => 'Platform.sh CLI',
  'userAgent' => 'platformsh-cli',
)/* END_CONFIG */;

        $required = ['envPrefix', 'manifestUrl', 'configDir', 'executable', 'cliName'];
        if ($missing = \array_diff($required, \array_keys($config))) {
            throw new \InvalidArgumentException('Missing required config key(s): ' . \implode(', ', $missing));
        }

        foreach ($config as $key => $value) {
            if (\property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        if (getenv($this->envPrefix . 'MANIFEST_URL') !== false) {
            $this->manifestUrl = getenv($this->envPrefix . 'MANIFEST_URL');
        } elseif ($manifestOption = $this->getOption('manifest')) {
            $this->manifestUrl = $manifestOption;
        }

        $this->userAgent = sprintf(
            '%s-installer (%s; %s; PHP %s)',
            $this->userAgent ?: 'cli',
            php_uname('s'),
            php_uname('r'),
            PHP_VERSION
        );
        $this->pharName = $this->executable . '.phar';
    }

    /**
     * Runs the install itself.
     */
    public function run() {
        error_reporting(E_ALL);
        ini_set('log_errors', 0);
        ini_set('display_errors', 1);

        $this->output($this->cliName . " installer", 'heading');

        // Run environment checks.
        $this->output(PHP_EOL . "Environment check", 'heading');

        // Check that the JSON and Phar extensions are installed (needed in this script).
        $this->checkExtension('json');
        $this->checkExtension('phar');

        // Check that Git is installed.
        $this->check(
            'Git is installed.',
            'Warning: Git will be needed.',
            function () {
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $command = 'where git';
                } else {
                    $command = 'command -v git';
                }
                exec($command, $output, $return_var);
                return $return_var === 0;
            },
            false
        );

        // Check other required extensions.
        $this->checkExtension('openssl');
        $this->checkExtension('pcre');

        $this->check(
            'The "curl" PHP extension is installed.',
            'The "curl" PHP extension is strongly recommended.',
            function () {
                return extension_loaded('curl');
            },
            false
        );

        // Check pcntl and posix - needed for tunnel:open and server:start.
        // Skip the check on Windows, as they are not available there anyway.
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->check(
                'The "pcntl" and "posix" extensions are installed.',
                'The "pcntl" and "posix" extensions are needed for some commands.',
                function () {
                    return extension_loaded('pcntl') && extension_loaded('posix');
                },
                false
            );
        }

        // Check Suhosin restrictions.
        if (extension_loaded('suhosin')) {
            $this->check(
                'The "phar" stream wrapper is allowed by Suhosin.',
                'The "phar" stream wrapper is blocked by Suhosin.',
                function () {
                    $allowed = ini_get('suhosin.executor.include.whitelist');
                    $blocked = ini_get('suhosin.executor.include.blacklist');

                    if ((false === stripos($allowed, 'phar'))
                        || (false !== stripos($blocked, 'phar'))
                    ) {
                        return false;
                    }

                    return true;
                }
            );
        }

        // Check whether PHP can open files via URLs.
        $this->check(
            'The "allow_url_fopen" setting is on.',
            'The "allow_url_fopen" setting is off; it must be on.',
            function () {
                return (true == ini_get('allow_url_fopen'));
            }
        );

        // Check a troublesome APC setting.
        $this->check(
            'The "apc.enable_cli" setting is off.',
            'Warning: the "apc.enable_cli" is on; this may cause problems with Phar files.',
            function () {
                return (false == ini_get('apc.enable_cli'));
            },
            false
        );

        // The necessary checks have passed. Start downloading the right version.
        $this->output(PHP_EOL . 'Download', 'heading');

        $latest = $this->performTask('Finding the latest version', function () {
            return $this->findLatestVersion($this->manifestUrl);
        });

        $this->performTask('Downloading version ' . $latest['version'], function () use ($latest) {
            $url = $latest['url'];

            // A relative download URL is treated as relative to the manifest URL.
            if (strpos($url, '//') === false && strpos($this->manifestUrl, '//') !== false) {
                $removePath = parse_url($this->manifestUrl, PHP_URL_PATH);
                $url = str_replace($removePath, '/' . ltrim($url, '/'), $this->manifestUrl);
            }

            $opts = $this->getStreamContextOpts(300);
            $contents = \file_get_contents($this->getAuthenticatedRedirect($url), false, \stream_context_create($opts));
            if ($contents === false) {
                return TaskResult::failure('The download failed');
            }
            if (!file_put_contents($this->pharName, $contents)) {
                return TaskResult::failure('Failed to write to file: ' . $this->pharName);
            }

            return TaskResult::success();
        });

        $pharPath = realpath($this->pharName) ?: $this->pharName;

        $this->performTask('Checking file integrity', function () use ($latest, $pharPath) {
            if ($latest['sha256'] !== hash_file('sha256', $pharPath)) {
                unlink($pharPath);

                return TaskResult::failure('The download was incomplete, or the file is corrupted');
            }

            return TaskResult::success();
        });

        $this->performTask('Checking that the file is a valid Phar', function () use ($pharPath) {
            try {
                new \Phar($pharPath);
            } catch (\Exception $e) {
                return TaskResult::failure(
                    'The file is not a valid Phar archive' . "\n" . $e->getMessage()
                );
            }

            return TaskResult::success();
        });

        $this->output(PHP_EOL . 'Install', 'heading');

        $this->performTask('Making the Phar executable', function () use ($pharPath) {
            if (!chmod($pharPath, 0755)) {
                return TaskResult::failure('Failed to make the Phar executable');
            }

            return TaskResult::success();
        });

        if ($homeDir = $this->getHomeDirectory()) {
            $pharPath = $this->performTask('Moving the Phar to your home directory', function () use ($pharPath, $homeDir) {
                $binDir = $homeDir . DIRECTORY_SEPARATOR . $this->configDir . DIRECTORY_SEPARATOR . 'bin';
                if (!is_dir($binDir) && !mkdir($binDir, 0700, true)) {
                    return TaskResult::failure('Failed to create directory: ' . $binDir);
                }

                $destination = $binDir . DIRECTORY_SEPARATOR . $this->executable;
                if (!rename($pharPath, $destination)) {
                    return TaskResult::failure('Failed to move the Phar to: ' . $destination);
                }

                return TaskResult::success($destination);
            });
            $this->output('  Executable location: ' . $pharPath);
        }

        $this->output(PHP_EOL . 'Running self:install command...' . PHP_EOL);
        $result = $this->selfInstall($pharPath);

        exit($result);
    }

    /**
     * Checks if a required PHP extension is installed.
     *
     * This attempts to give configuration advice if the extension exists but
     * is not yet enabled.
     *
     * @param string $extension
     */
    private function checkExtension($extension) {
        if (\extension_loaded($extension)) {
            $this->output('  [*] The "' . $extension . '" PHP extension is installed.', 'success');
            return;
        }
        $this->output('  [X] The ' . $extension . ' PHP extension is required.', 'error');
        $extFilename = DIRECTORY_SEPARATOR === '\\' ? 'php_' . $extension . '.dll' : $extension . '.so';
        $extDirs = [
            PHP_EXTENSION_DIR,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext',
        ];
        foreach ($extDirs as $dir) {
            $extPath = $dir . DIRECTORY_SEPARATOR . $extFilename;
            if (!\file_exists($extPath)) {
                continue;
            }
            $this->output("The extension already exists at: $extPath");
            if (!empty(PHP_CONFIG_FILE_SCAN_DIR) && \is_dir(PHP_CONFIG_FILE_SCAN_DIR)) {
                $this->output(
                    "\nTo enable it, create a file named: " . PHP_CONFIG_FILE_SCAN_DIR . DIRECTORY_SEPARATOR . "$extension.ini"
                    . "\ncontaining this line:"
                    . "\nextension=$extPath"
                );
            } else {
                $this->output(
                    "\nTo enable it, edit your php.ini configuration file and add the line:"
                    . "\nextension=$extPath"
                );
            }
            break;
        }
        exit(1);
    }

    /**
     * Runs the 'self:install' command.
     *
     * @param string $pharPath The path of the Phar executable.
     *
     * @return int The command's exit code.
     */
    private function selfInstall($pharPath) {
        $command = 'php ' . escapeshellarg($pharPath) . ' self:install --yes';
        if ($shellType = $this->getOption('shell-type')) {
            $command .= ' --shell-type ' . escapeshellarg($shellType);
        }
        putenv('CLICOLOR_FORCE=' . ($this->terminalSupportsAnsi() ? '1' : '0'));

        return $this->runCommand($command, true);
    }

    /**
     * Finds the latest version to download from the manifest.
     *
     * @param string $manifestUrl
     *
     * @return TaskResult
     */
    private function findLatestVersion($manifestUrl) {
        $manifest = file_get_contents($manifestUrl, false, \stream_context_create($this->getStreamContextOpts(15)));
        if ($manifest === false) {
            return TaskResult::failure('Failed to download manifest file: ' . $manifestUrl);
        }
        $manifest = json_decode($manifest, true);
        if ($manifest === null) {
            return TaskResult::failure('Failed to decode manifest file: ' . $manifestUrl);
        }

        $allowedSuffixes = ['stable'];
        foreach (['beta', 'alpha', 'dev'] as $suffix) {
            if ($this->flagEnabled($suffix)) {
                $allowedSuffixes[] = $suffix;
            }
        }
        $phpVersion = PHP_VERSION;
        $resolver = new VersionResolver();
        $versions = $resolver->findInstallableVersions($manifest, $phpVersion, $allowedSuffixes);
        if (empty($versions)) {
            return TaskResult::failure($resolver->explainNoInstallableVersions($manifest, $phpVersion, $allowedSuffixes));
        }
        try {
            $latest = $resolver->findLatestVersion($versions, $this->getOption('min'), $this->getOption('max'));
        } catch (\Exception $e) {
            return TaskResult::failure($e->getMessage());
        }

        return TaskResult::success($latest);
    }

    /**
     * @param string   $summaryText Description of the task.
     * @param callable $task        A function that returns a TaskResult.
     * @param string   $indent      Whether to indent the summary & errors.
     *
     * @return mixed The result of the task, if any.
     */
    private function performTask($summaryText, $task, $indent = '  ') {
        $this->output($indent . $summaryText . '...', null, false);
        /** @var TaskResult $result */
        $result = $task();
        if (!$result->isSuccess()) {
            $this->output('');
            if ($message = $result->getMessage()) {
                $this->output('Error: ' . $message, 'error');
            }
            exit(1);
        }
        $this->output(' done', 'success');

        return $result->getData();
    }

    /**
     * Runs a shell command.
     *
     * @param string $cmd
     * @param bool $forceStdout Whether to redirect all stderr output to stdout.
     *
     * @return int The command's exit code.
     */
    private function runCommand($cmd, $forceStdout = false) {
        /*
         * Set up the STDIN, STDOUT and STDERR constants.
         *
         * Due to a PHP bug, these constants are not available when the PHP script
         * is being read from stdin.
         *
         * See https://bugs.php.net/bug.php?id=43283
         */
        if (!defined('STDIN')) {
            define('STDIN', fopen('php://stdin', 'r'));
        }
        if (!defined('STDOUT')) {
            define('STDOUT', fopen('php://stdout', 'w'));
        }
        if (!defined('STDERR')) {
            define('STDERR', fopen('php://stderr', 'w'));
        }

        $process = proc_open($cmd, [STDIN, STDOUT, $forceStdout ? STDOUT : STDERR], $pipes);

        return proc_close($process);
    }

    /**
     * Checks a condition, outputs a message, and exits if failed.
     *
     * @param string   $success   The success message.
     * @param string   $failure   The failure message.
     * @param callable $condition The condition to check.
     * @param bool     $exit      Whether to exit on failure.
     */
    private function check($success, $failure, $condition, $exit = true) {
        if ($condition()) {
            $this->output('  [*] ' . $success, 'success');
        }
        elseif (!$exit) {
            $this->output('  [!] ' . $failure, 'warning');
        }
        else {
            $this->output('  [X] ' . $failure, 'error');
            exit(1);
        }
    }

    /**
     * Outputs formatted text.
     *
     * @param string $text
     * @param string $color
     * @param bool   $newLine
     */
    private function output($text, $color = null, $newLine = true) {
        static $styles = [
            'success' => "\033[0;32m%s\033[0m",
            'error' => "\033[31;31m%s\033[0m",
            'info' => "\033[33m%s\033[39m",
            'warning' => "\033[33m%s\033[39m",
            'heading' => "\033[1;33m%s\033[22;39m",
        ];

        $format = '%s';

        if (isset($styles[$color]) && $this->terminalSupportsAnsi()) {
            $format = $styles[$color];
        }

        if ($newLine) {
            $format .= PHP_EOL;
        }

        printf($format, $text);
    }

    /**
     * Test if a flag is on the command line.
     *
     * @param string $flag A flag name (only letters, shortcuts not supported).
     *
     * @return bool
     */
    private function flagEnabled($flag) {
        return in_array('--' . $flag, $this->argv, true);
    }

    /**
     * Get a command-line option that requires a value.
     *
     * @param string $name An option name (only letters, shortcuts not supported).
     *
     * @return string
     */
    private function getOption($name) {
        foreach ($this->argv as $key => $arg) {
            if (strpos($arg, '--' . $name . '=') === 0) {
                return substr($arg, strlen('--' . $name . '='));
            }
            $next = isset($this->argv[$key + 1]) && substr($this->argv[$key + 1], 0, 1) !== '-'
                ? $this->argv[$key + 1]
                : '';
            if ($arg === '--' . $name) {
                if ($next === '') {
                    throw new \InvalidArgumentException('Option --' . $name . ' requires a value');
                }
                return $next;
            }
        }

        return '';
    }

    /**
     * Returns whether to use ANSI escape sequences.
     *
     * @return bool
     */
    private function terminalSupportsAnsi() {
        static $ansi;
        if (isset($ansi)) {
            return $ansi;
        }

        global $argv;
        if (!empty($argv)) {
            if ($this->flagEnabled('no-ansi')) {
                return $ansi = false;
            }
            elseif ($this->flagEnabled('ansi')) {
                return $ansi = true;
            }
        }

        // On Windows, default to no ANSI, except in ANSICON and ConEmu.
        // Everywhere else, default to ANSI if stdout is a terminal.
        /** @noinspection PhpComposerExtensionStubsInspection */
        return $ansi = (DIRECTORY_SEPARATOR == '\\')
            ? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
            : (function_exists('posix_isatty') && posix_isatty(1));
    }

    /**
     * Finds the user's home directory.
     *
     * @return string|false
     *   The user's home directory as an absolute path, or false on failure.
     */
    private function getHomeDirectory() {
        $vars = [$this->envPrefix . 'HOME', 'HOME', 'USERPROFILE'];
        foreach ($vars as $var) {
            if ($home = getenv($var)) {
                return realpath($home) ?: $home;
            }
        }
        if (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
            return $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        }

        return false;
    }

    /**
     * Constructs stream context options for downloading files.
     *
     * @param int $timeout
     *
     * @return array
     */
    private function getStreamContextOpts($timeout) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'follow_location' => 1,
                'timeout' => $timeout,
                'user_agent' => $this->userAgent,
            ],
        ];
        if ($proxy = $this->getProxy()) {
            $opts['http']['proxy'] = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $proxy);
        }
        if ($this->flagEnabled('insecure')) {
            $opts['ssl']['verify_peer'] = false;
            $opts['ssl']['verify_peer_name'] = false;
        } elseif ($path = $this->getCaBundle()) {
            if (\is_dir($path)) {
                $opts['ssl']['capath'] = $path;
            } else {
                $opts['ssl']['cafile'] = $path;
            }
        }

        return $opts;
    }

    /**
     * Returns the path to the system CA bundle, if found.
     *
     * Adapted from composer/ca-bundle.
     * @link https://github.com/composer/ca-bundle
     * @see \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath()
     *
     * @return string|false
     */
    private function getCaBundle() {
        static $path;
        if (isset($path)) {
            return $path;
        }

        $caBundlePaths = [];

        $caBundlePaths[] = \getenv('SSL_CERT_FILE');
        $caBundlePaths[] = \getenv('SSL_CERT_DIR');

        $caBundlePaths[] = \ini_get('openssl.cafile');
        $caBundlePaths[] = \ini_get('openssl.capath');

        $otherLocations = [
            '/etc/pki/tls/certs/ca-bundle.crt', // Fedora, RHEL, CentOS (ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem', // SUSE, openSUSE (ca-certificates package)
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD (ca_root_nss_package)
            '/usr/ssl/certs/ca-bundle.crt', // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt', // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt', // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt', // Really old RedHat?
            '/etc/ssl/cert.pem', // OpenBSD
            '/usr/local/etc/ssl/cert.pem', // FreeBSD 10.x
            '/usr/local/etc/openssl/cert.pem', // OS X homebrew, openssl package
            '/usr/local/etc/openssl@1.1/cert.pem', // OS X homebrew, openssl@1.1 package
        ];

        foreach ($otherLocations as $location) {
            $otherLocations[] = \dirname($location);
        }

        $caBundlePaths = \array_filter(\array_merge($caBundlePaths, $otherLocations));

        foreach ($caBundlePaths as $candidate) {
            if ($this->caPathUsable($candidate)) {
                return $path = $candidate;
            }
        }

        return $path = false;
    }

    /**
     * Returns if a CA bundle path should be used.
     *
     * Adapted from composer/ca-bundle.
     * @link https://github.com/composer/ca-bundle
     * @see \Composer\CaBundle\CaBundle::caFileUsable()
     * @see \Composer\CaBundle\CaBundle::caDirUsable()
     *
     * @param string $path
     *
     * @return bool
     */
    private function caPathUsable($path)
    {
        if (!\is_readable($path)) {
            return false;
        }
        if (\is_file($path)) {
            // Avoid openssl_x509_parse() on old PHP versions (CVE-2013-6420).
            if (\function_exists('\\openssl_x509_parse') && PHP_VERSION_ID >= 50600) {
                $contents = \file_get_contents($path);
                if (!$contents || \strlen($contents) === 0) {
                    return false;
                }
                $contents = \str_replace('TRUSTED CERTIFICATE', 'CERTIFICATE', $contents);
                return $contents !== false && \openssl_x509_parse($contents);
            }
            return false;
        }
        if (\is_dir($path)) {
            return (bool) \glob($path . '/*');
        }
        return false;
    }

    /**
     * If possible, this converts a URL to an authenticated redirect.
     *
     * This only affects GitHub for now.
     *
     * @param string $url
     *
     * @return string
     *   An authenticated redirection URL, if possible. Otherwise the original URL is returned.
     */
    private function getAuthenticatedRedirect($url) {
        if (\strpos($url, '//github.com') === false) {
            return $url;
        }
        $headers = $this->authHeaders($url);
        if (!$headers) {
            return $url;
        }
        $opts = $this->getStreamContextOpts(300);
        $opts['http']['header'] = implode("\r\n", $headers);
        $opts['http']['follow_location'] = 0;
        $opts['http']['ignore_errors'] = true;
        \file_get_contents($url, false, \stream_context_create($opts));
        // Check for a 301 or 302 response.
        $headers = isset($http_response_header) ? $http_response_header : [];
        if (isset($headers[0]) && \strpos($headers[0], ' 30') !== false) {
            foreach ($headers as $header) {
                // Read the Location header.
                if (\stripos($header, 'Location: ') === 0) {
                    return \trim(\substr($header, 10));
                }
            }
        }
        return $url;
    }

    /**
     * Generates authentication headers based on the request URL.
     *
     * At the moment this just supports github.com.
     *
     * @param string $url
     *
     * @return string[]
     */
    private function authHeaders($url) {
        $host = \parse_url($url, PHP_URL_HOST);

        if ($host === 'github.com') {
            // Use the GITHUB_TOKEN in the environment, if available.
            if ($token = \getenv('GITHUB_TOKEN')) {
                return ['Authorization: token ' . $token];
            }

            // Use COMPOSER_AUTH and decode it.
            // See https://getcomposer.org/doc/06-config.md#github-oauth
            if (($composer_auth = \getenv('COMPOSER_AUTH'))
                && ($json = \json_decode($composer_auth, true)) !== null
                && !empty($json['github-oauth'][$host])) {
                return ['Authorization: token ' . $json['github-oauth'][$host]];
            }

            // Read the local GitHub token from the project container.
            // The token allows for higher rate limits but is otherwise unprivileged.
            if (\getenv('HOME') !== false) {
                $authFilename = \getenv('HOME') . '/.global/auth.json';
                if (\is_readable($authFilename)
                    && ($contents = \file_get_contents($authFilename)) !== false
                    && ($json = \json_decode($authFilename, true)) !== null
                    && !empty($json['github-oauth'][$host])) {
                    return ['Authorization: token ' . $json['github-oauth'][$host]];
                }
            }
        }
        return [];
    }

    /**
     * Finds a proxy address based on the https_proxy or http_proxy environment variable.
     *
     * @return string|null
     */
    private function getProxy() {
        // The proxy variables should be ignored in a non-CLI context.
        // This check has probably already been run, but it's important.
        if (PHP_SAPI !== 'cli') {
            return null;
        }
        foreach (['https', 'http'] as $scheme) {
            if ($proxy = getenv($scheme . '_proxy')) {
                return $proxy;
            }
        }
        return null;
    }
}

class TaskResult {
    private $success = false;
    private $message = '';
    private $data;

    private function __construct($success, $message = '', $data = null) {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
    }

    public static function success($data = null) {
        return new self(true, '', $data);
    }

    public static function failure($errorMessage) {
        return new self(false, $errorMessage);
    }

    public function isSuccess() {
        return $this->success;
    }

    public function getMessage() {
        return $this->message;
    }

    public function getData() {
        return $this->data;
    }
}

class VersionResolver {
    /**
     * Finds the latest installable version in the manifest.
     *
     * @param array  $versions
     * @param string $phpVersion
     * @param array  $allowedSuffixes
     *
     * @return array
     *   A list of versions, filtered by those that are installable.
     */
    public function findInstallableVersions(array $versions, $phpVersion = PHP_VERSION, array $allowedSuffixes = ['stable']) {
        $installable = [];
        foreach ($versions as $version) {
            if (isset($version['php']['min']) && version_compare($version['php']['min'], $phpVersion, '>')) {
                continue;
            }
            if ($dashPos = strpos($version['version'], '-')) {
                $suffix = substr($version['version'], $dashPos + 1);
                if (!in_array($suffix, $allowedSuffixes) && !in_array('dev', $allowedSuffixes)) {
                    continue;
                }
            }
            $installable[] = $version;
        }

        return $installable;
    }

    /**
     * Explains why no installable versions could be found.
     *
     * @param array  $versions
     * @param string $phpVersion
     * @param array  $allowedSuffixes
     *
     * @return string
     */
    public function explainNoInstallableVersions(array $versions, $phpVersion = PHP_VERSION, array $allowedSuffixes = ['stable']) {
        $reasons = [];
        foreach ($versions as $version) {
            $name = 'v' . $version['version'];
            if (isset($version['php']['min']) && version_compare($version['php']['min'], $phpVersion, '>')) {
                $reasons[] = sprintf('Version %s requires PHP %s (current PHP version is %s)', $name, $version['php']['min'], $phpVersion);
                continue;
            }
            if ($dashPos = strpos($version['version'], '-')) {
                $suffix = substr($version['version'], $dashPos + 1);
                if (!in_array($suffix, $allowedSuffixes) && !in_array('dev', $allowedSuffixes)) {
                    $reasons[] = sprintf('Version %s has the suffix -%s, not allowed', $name, $suffix);
                    continue;
                }
            }
        }

        $explanation = 'No installable versions were found.';
        if (count($reasons)) {
            foreach ($reasons as $reason) {
                $explanation .= "\n    - $reason";
            }
        }

        return $explanation;
    }

    /**
     * Finds the latest version in a list of versions.
     *
     * @param array  $versions
     * @param string $min
     * @param string $max
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    public function findLatestVersion(array $versions, $min = '', $max = '') {
        usort($versions, function (array $a, array $b) {
            return version_compare($a['version'], $b['version']);
        });
        $selected = [];
        foreach ($versions as $version) {
            $satisfiesMin = $min === '' || version_compare($version['version'], ltrim($min, 'v'), '>=');
            $satisfiesMax = $max === '' || version_compare($version['version'], ltrim($max, 'v'), '<=');
            if ($satisfiesMin && $satisfiesMax) {
                $selected = $version;
            }
        }
        if (empty($selected)) {
            $message = 'Failed to find a version';
            if ($min !== '') {
                $message .= ' >= ' . $min;
            }
            if ($max !== '') {
                if ($min !== '') {
                    $message .= ' and';
                }
                $message .= ' <= ' . $max;
            }
            throw new \RuntimeException($message);
        }

        return $selected;
    }
}
