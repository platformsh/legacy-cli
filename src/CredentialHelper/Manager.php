<?php

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Filesystem\Filesystem;

class Manager {

    private $config;
    private $shell;

    private static $isInstalled;

    public function __construct(Config $config, Shell $shell = null)
    {
        $this->config = $config;
        $this->shell = $shell ?: new Shell();
    }

    /**
     * Checks if any credential helper can be used on this system.
     *
     * @return bool
     */
    public function isSupported() {
        if ($this->config->getWithDefault('api.disable_credential_helpers', false)) {
            return false;
        }
        try {
            $this->getHelper();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Checks if the credential helper is installed already.
     *
     * @return bool
     */
    public function isInstalled() {
        if (self::$isInstalled) {
            return true;
        }

        try {
            $helper = $this->getHelper();
        } catch (\RuntimeException $e) {
            return self::$isInstalled = false;
        }

        $path = $this->getExecutablePath();

        return self::$isInstalled = $this->helperExists($helper, $path);
    }

    /**
     * Installs the credential helper.
     */
    public function install() {
        if ($this->isInstalled()) {
            return;
        }

        $this->download($this->getHelper(), $this->getExecutablePath());

        self::$isInstalled = true;
    }

    /**
     * Stores a secret.
     *
     * @param string $serverUrl
     * @param string $secret
     */
    public function store($serverUrl, $secret) {
        $this->exec('store', json_encode([
            'ServerURL' => $serverUrl,
            'Username' => (string) (OsUtil::isWindows() ? getenv('USERNAME') : getenv('USER')),
            'Secret' => $secret,
        ]));
    }

    /**
     * Erases a secret.
     *
     * @param string $serverUrl
     */
    public function erase($serverUrl) {
        try {
            $this->exec('erase', $serverUrl);
        } catch (ProcessFailedException $e) {
            // Ignore an item not found error.
            if (stripos($e->getProcess()->getOutput(), 'could not be found') !== false) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Loads a secret.
     *
     * @param string $serverUrl
     *
     * @return string|false
     */
    public function get($serverUrl) {
        try {
            $data = $this->exec('get', $serverUrl);
        } catch (ProcessFailedException $e) {
            if (stripos($e->getProcess()->getOutput(), 'credentials not found') !== false) {
                return false;
            }
            throw $e;
        }

        if (is_string($data)) {
            $json = json_decode($data, true);
            if ($json === null || !isset($json['Secret'])) {
                throw new \RuntimeException('Failed to decode JSON from credential helper');
            }

            return $json['Secret'];
        }

        return false;
    }

    /**
     * Lists all secrets.
     *
     * @return array
     */
    public function listAll() {
        $data = $this->exec('list');

        return (array) json_decode($data, true);
    }

    /**
     * Verifies that a helper exists and is executable at a path.
     *
     * @param array $helper
     * @param string $path
     *
     * @return bool
     */
    private function helperExists(array $helper, $path) {
        if (!file_exists($path) || !is_executable($path)) {
            return false;
        }

        return hash_file('sha256', $path) === $helper['sha256'];
    }

    /**
     * Downloads, extracts, and moves the credential helper to a destination.
     *
     * @param array $helper
     * @param string $destination
     */
    private function download(array $helper, $destination) {
        if ($this->helperExists($helper, $destination)) {
            return;
        }

        // Download from the URL.
        $contextOptions = $this->config->getStreamContextOptions(60);
        $contextOptions['http']['follow_location'] = 1;
        $contents = file_get_contents($helper['url'], false, \stream_context_create($contextOptions));
        if (!$contents) {
            throw new \RuntimeException('Failed to download credentials helper from URL: ' . $helper['url']);
        }

        // Work in a temporary file.
        $fs = new Filesystem();
        $tmpFile = $fs->tempnam(sys_get_temp_dir(), 'cli-helper-extracted');
        try {
            // Write the file.
            $bytes = file_put_contents($tmpFile, $contents);
            if (!$bytes) {
                throw new \RuntimeException(sprintf('Failed to write downloaded helper file to: %s', $tmpFile));
            }

            // Verify the file hash.
            $hash = hash_file('sha256', $tmpFile);
            if ($hash !== $helper['sha256']) {
                throw new \RuntimeException(sprintf('Failed to verify downloaded file for helper: %s (size: %d, hash: %s, expected: %s)', $helper['url'], $bytes, $hash, $helper['sha256']));
            }

            // Make the file executable and move it into place.
            $fs->chmod($tmpFile, 0700);
            $fs->mkdir(dirname($destination), 0700);
            $fs->rename($tmpFile, $destination, true);
        } finally {
            $fs->remove($tmpFile);
        }
    }

    /**
     * @return array
     */
    private function getHelpers() {
        return [
            'windows' => [
                'amd64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-wincred-v0.8.1.windows-amd64.exe',
                    'filename' => 'docker-credential-wincred.exe',
                    'sha256' => '86c3aa9120ad136e5f7d669267a8e271f0d9ec2879c75908f20a769351043a28',
                ],
                'arm64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-wincred-v0.8.1.windows-arm64.exe',
                    'filename' => 'docker-credential-wincred.exe',
                    'sha256' => 'a83bafb13c168de1ecae48dbfc5e6f220808be86dd4258dd72fd9bcefdf5a63c',
                ],
            ],
            'linux' => [
                'amd64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-secretservice-v0.8.1.linux-amd64',
                    'filename' => 'docker-credential-secretservice',
                    'sha256' => '9a5875bc9435c2c8f9544419c249f866b372b054294f169444f66bb925d96edc',
                ],
                'arm64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-secretservice-v0.8.1.linux-arm64',
                    'filename' => 'docker-credential-secretservice',
                    'sha256' => '1093ff44716b9d8c3715d0e5322ba453fd1a77faad8b7e1ba3ad159bf6e10887',
                ],
            ],
            'darwin' => [
                'amd64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-osxkeychain-v0.8.1.darwin-amd64',
                    'filename' => 'docker-credential-osxkeychain',
                    'sha256' => '7acd433a8ab95c3180ef740ce30aa3d21d2877f4ceb35de797e4eb595168e3c8',
                ],
                'arm64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.8.1/docker-credential-osxkeychain-v0.8.1.darwin-arm64',
                    'filename' => 'docker-credential-osxkeychain',
                    'sha256' => '0db0f8e7e3db93a720da55760bbe26e1266648515b8a0a9539185a5503d03449',
                ],
            ],
        ];
    }

    /**
     * Finds a helper package for this system.
     *
     * @return array
     */
    private function getHelper() {
        $arch = php_uname('m');
        if ($arch === 'ARM64') {
            $arch = 'arm64';
        } elseif (\in_array($arch, ['x86_64', 'amd64', 'AMD64'])) {
            $arch = 'amd64';
        }

        $helpers = $this->getHelpers();

        if (OsUtil::isWindows()) {
            $os = 'windows';
        } elseif (OsUtil::isLinux()) {
            $os = 'linux';
        } elseif (OsUtil::isOsX()) {
            $os = 'darwin';
        }

        if (!isset($os) || !isset($helpers[$os])) {
            throw new \RuntimeException('Unable to find a credentials helper for this operating system');
        }
        if (!isset($helpers[$os][$arch])) {
            throw new \RuntimeException(sprintf('Unable to find a credentials helper for this operating system (%s) and architecture (%s)', $os, $arch));
        }

        if ($os === 'linux') {
            // The Linux helper probably needs an X display.
            if (in_array(getenv('DISPLAY'), [false, 'none'], true)) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (no DISPLAY)');
            }

            // Check if there is a Gnome session.
            $xdgDesktop = getenv('XDG_CURRENT_DESKTOP');
            if ($xdgDesktop === false || stripos($xdgDesktop, 'GNOME') === false) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (Gnome session not detected)');
            }

            // Disable use of the secret-service helper inside a snap or Docker container.
            if (getenv('SNAP_CONTEXT') !== false || getenv('container') !== false || getenv('DOCKER_IP') !== false || @file_exists('/.dockerenv')) {
                throw new \RuntimeException("Unable to find a credentials helper for this system (secret-service doesn't work properly inside a container)");
            }

            // The Linux helper needs "libsecret" to be installed.
            if (!$this->shell->execute('ldconfig --print-cache | grep -q libsecret')) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (libsecret is not installed)');
            }
        }

        return $helpers[$os][$arch];
    }

    /**
     * Executes a command on the credential helper.
     *
     * @param string $command
     * @param mixed|null $input
     *
     * @return string|bool
     */
    private function exec($command, $input = null) {
        $this->install();

        return $this->shell->execute([$this->getExecutablePath(), $command], null, true, true, [], 10, $input);
    }

    /**
     * Finds the absolute path to the credential helper executable.
     *
     * @return string
     */
    private function getExecutablePath() {
        $path = $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . 'credential-helper';
        if (OsUtil::isWindows()) {
            $path .= '.exe';
        }

        return $path;
    }
}
