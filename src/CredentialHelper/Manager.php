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
        $contents = file_get_contents($helper['url']);
        if (!$contents) {
            throw new \RuntimeException('Failed to download credentials helper from URL: ' . $helper['url']);
        }

        // Work in a temporary file.
        $fs = new Filesystem();
        $tmpFile = $fs->tempnam(sys_get_temp_dir(), 'cli-helper-extracted');
        try {
            // Extract the archive.
            $this->extractBinFromArchive($contents, substr($helper['url'], -4) === '.zip', $helper['filename'], $tmpFile);

            // Verify the file hash.
            if (hash_file('sha256', $tmpFile) !== $helper['sha256']) {
                throw new \RuntimeException('Failed to verify downloaded file for helper: ' . $helper['url']);
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
     * Extracts the internal file from the package and moves it to a destination.
     *
     * @param string $archiveContents
     * @param bool   $zip
     * @param string $internalFilename
     * @param string $destination
     */
    private function extractBinFromArchive($archiveContents, $zip, $internalFilename, $destination) {
        $fs = new Filesystem();
        $tmpDir = $tmpFile = $fs->tempnam(sys_get_temp_dir(), 'cli-helpers');
        $fs->remove($tmpFile);
        $fs->mkdir($tmpDir);
        try {
            $tmpFile = $fs->tempnam($tmpDir, 'cli-helper');
            if (!file_put_contents($tmpFile, $archiveContents)) {
                throw new \RuntimeException('Failed to write credentials helper to file: ' . $tmpFile);
            }
            if ($zip) {
                if (class_exists('\\ZipArchive')) {
                    $zip = new \ZipArchive();
                    if (!$zip->open($tmpFile) || !$zip->extractTo($tmpDir) || !$zip->close()) {
                        throw new \RuntimeException('Failed to extract zip: ' . ($zip->getStatusString() ?: 'unknown error'));
                    }
                } elseif ($this->shell->commandExists('unzip')) {
                    $command = 'unzip ' . escapeshellarg($tmpFile) . ' -d ' . escapeshellarg($tmpDir);
                    $this->shell->execute($command, null, true);
                } else {
                    throw new \RuntimeException('Failed to extract zip: unzip is not installed');
                }
            } else {
                $command = 'tar -xzp -f ' . escapeshellarg($tmpFile) . ' -C ' . escapeshellarg($tmpDir);
                $this->shell->execute($command, null, true);
            }
            if (!file_exists($tmpDir . DIRECTORY_SEPARATOR . $internalFilename)) {
                throw new \RuntimeException('File not found: ' . $tmpDir . DIRECTORY_SEPARATOR . $internalFilename);
            }
            $fs->rename($tmpDir . DIRECTORY_SEPARATOR . $internalFilename, $destination, true);
        } finally {
            $fs->remove($tmpDir);
        }
    }

    /**
     * @return array
     */
    private function getHelpers() {
        return [
            'wincred' => [
                'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-wincred-v0.6.4-amd64.zip',
                'filename' => 'docker-credential-wincred.exe',
                'sha256' => 'a4e7885d3d469b2e3c92ab72c729e35df94ed1952bc8090272107fef0652e474',
            ],
            'secretservice' => [
                'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-secretservice-v0.6.4-amd64.tar.gz',
                'filename' => 'docker-credential-secretservice',
                'sha256' => '1bddcc1da7ea4d6f50d8e21ba3f0d2ae518c04d90553f543e683af62e9c7c9a8',
            ],
            'osxkeychain' => [
                'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-osxkeychain-v0.6.4-amd64.tar.gz',
                'filename' => 'docker-credential-osxkeychain',
                'sha256' => '76c4088359bbbcd25b8d0ff8436086742b6184aba6380ae57d39e5513f723b74',
            ],
            'osxkeychain-arm64' => [
                'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-osxkeychain-v0.6.4-arm64.tar.gz',
                'filename' => 'docker-credential-osxkeychain',
                'sha256' => '902e8237747aac0eca61efa1875e65aa8552b7c95fc406cf0d2aef733dda41de',
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

        $helpers = $this->getHelpers();

        if (OsUtil::isOsX()) {
            if ($arch === 'arm64') {
                return $helpers['osxkeychain-arm64'];
            } elseif (\in_array($arch, ['x86_64', 'amd64', 'AMD64'])) {
                return $helpers['osxkeychain'];
            }
        }

        if (!in_array($arch, ['x86_64', 'amd64', 'AMD64'])) {
            throw new \RuntimeException('Unable to find a credentials helper for this system architecture');
        }

        if (OsUtil::isWindows()) {
            return $helpers['wincred'];
        }

        if (OsUtil::isLinux()) {
            // The Linux helper probably needs an X display.
            if (in_array(getenv('DISPLAY'), [false, 'none'], true)) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (no DISPLAY)');
            }

            // Check if there is a Gnome session.
            $xdgDesktop = getenv('XDG_CURRENT_DESKTOP');
            if ($xdgDesktop === false || stripos($xdgDesktop, 'GNOME') === false) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (Gnome session not detected)');
            }

            // The Linux helper needs "libsecret" to be installed.
            if (!$this->shell->execute('ldconfig --print-cache | grep -q libsecret')) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (libsecret is not installed)');
            }

            return $helpers['secretservice'];
        }

        throw new \RuntimeException('Unable to find a credentials helper for this system');
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
