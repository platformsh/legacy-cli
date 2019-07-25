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
 *      --min                  Min version to install.
 *      --max                  Max version to install (not recommended).
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
    private $manifestUrl;
    private $configDir;
    private $executable;
    private $cliName;
    private $pharName;
    private $argv;

    public function __construct(array $args = []) {
        $this->manifestUrl = getenv('PLATFORMSH_CLI_MANIFEST_URL') ?: 'https://platform.sh/cli/manifest.json';
        $this->configDir = '.platformsh';
        $this->executable = 'platform';
        $this->cliName = 'Platform.sh CLI';
        $this->pharName = $this->executable . '.phar';
        $this->argv = !empty($args) ? $args : $GLOBALS['argv'];
    }

    /**
     * Runs the install itself.
     */
    public function run() {
        $this->output($this->cliName . " installer", 'heading');

        // Run environment checks.
        $this->output(PHP_EOL . "Environment check", 'heading');

        // Check that the JSON extension is installed (needed in this script).
        $this->check(
            'The "json" PHP extension is installed.',
            'The "json" PHP extension is required.',
            function () {
                return extension_loaded('json');
            }
        );

        // Check that the Phar extension is installed (needed in this script).
        $this->check(
            'The "phar" PHP extension is installed.',
            'The "phar" PHP extension is required.',
            function () {
                return extension_loaded('phar');
            }
        );

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

        $required_extensions = [
            'mbstring',
            'openssl',
            'pcre',
        ];
        foreach ($required_extensions as $extension) {
            $this->check(
                'The "' . $extension . '" PHP extension is installed.',
                'Warning: the "' . $extension . '" PHP extension will be needed.',
                function () use ($extension) {
                    return extension_loaded($extension);
                },
                false
            );
        }

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

        $this->output('  Finding the latest version...');
        $manifest = file_get_contents($this->manifestUrl);
        if ($manifest === false) {
            $this->output('  Failed to download manifest file: ' . $this->manifestUrl, 'error');
            exit(1);
        }

        $manifest = json_decode($manifest, true);
        if ($manifest === null) {
            $this->output('  Failed to decode manifest file: ' . $this->manifestUrl, 'error');
            exit(1);
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
            $this->output('  ' . $resolver->explainNoInstallableVersions($manifest, $phpVersion, $allowedSuffixes), 'error');
            exit(1);
        }
        try {
            $latest = $resolver->findLatestVersion($versions, $this->getOption('min'), $this->getOption('max'));
        } catch (\Exception $e) {
            $this->output('  ' . $e->getMessage(), 'error');
            exit(1);
        }

        $this->output("  Downloading version {$latest['version']}...");
        if (!file_put_contents($this->pharName, file_get_contents($latest['url']))) {
            $this->output('  The download failed.', 'error');
        }

        $pharPath = realpath($this->pharName) ?: $this->pharName;

        $this->output('  Checking file integrity...');
        if ($latest['sha256'] !== hash_file('sha256', $pharPath)) {
            unlink($pharPath);
            $this->output('  The download was corrupted.', 'error');
            exit(1);
        }

        $this->output('  Checking that the file is a valid Phar (PHP Archive)...');

        try {
            new \Phar($pharPath);
        } catch (\Exception $e) {
            $this->output('  The file is not a valid Phar archive.', 'error');
            $this->output('  ' . $e->getMessage(), 'error');
            exit(1);
        }

        $this->output(PHP_EOL . 'Install', 'heading');

        $this->output('  Making the Phar executable...');
        if (!chmod($pharPath, 0755)) {
            $this->output('  Failed to make the Phar executable: ' . $pharPath, 'warning');
        }

        if ($homeDir = $this->getHomeDirectory()) {
            $this->output('  Moving the Phar to your home directory...');
            $binDir = $homeDir . '/' . $this->configDir . '/bin';
            if (!is_dir($binDir) && !mkdir($binDir, 0700, true)) {
                $this->output('  Failed to create directory: ' . $binDir, 'error');
            }
            elseif (!rename($pharPath, $binDir . '/' . $this->executable)) {
                $this->output('  Failed to move the Phar to: ' . $binDir . '/' . $this->executable, 'error');
            }
            else {
                $pharPath = $binDir . '/' . $this->executable;
                $this->output('  Successfully moved the Phar to: ' . $pharPath);
            }
        }

        $this->output(PHP_EOL . '  Running self:install command...' . PHP_EOL);
        putenv('CLICOLOR_FORCE=' . ($this->terminalSupportsAnsi() ? '1' : '0'));
        $result = $this->runCommand('php ' . $pharPath . ' self:install --yes');

        exit($result);
    }

    /**
     * @param string $cmd
     *
     * @return int
     */
    private function runCommand($cmd) {
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

        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);

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
        else {
            $this->output('  [ ] ' . $failure, $exit ? 'error' : 'warning');

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
        $value = '';
        foreach ($this->argv as $key => $arg) {
            if (strpos($arg, '--' . $name . '=') === 0) {
                $value = substr($arg, strlen('--' . $name . '='));
                break;
            }
            $next = isset($this->argv[$key + 1]) && substr($this->argv[$key + 1], 0, 1) !== '-'
                ? $this->argv[$key + 1]
                : '';
            if ($arg === '--' . $name) {
                if ($next === '') {
                    throw new \InvalidArgumentException('Option --' . $name . ' requires a value');
                }
                $value = $next;
                break;
            }
        }

        return $value;
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
        if ($home = getenv('HOME')) {
            return $home;
        }
        elseif ($userProfile = getenv('USERPROFILE')) {
            return $userProfile;
        }
        elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
            return $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        }

        return false;
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
                if (!in_array($suffix, $allowedSuffixes)) {
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
                if (!in_array($suffix, $allowedSuffixes)) {
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
