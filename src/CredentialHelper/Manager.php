<?php

declare(strict_types=1);

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Filesystem\Filesystem;

class Manager
{
    private readonly Shell $shell;

    private static ?bool $isInstalled = null;

    public function __construct(private readonly Config $config, ?Shell $shell = null)
    {
        $this->shell = $shell ?: new Shell();
    }

    /**
     * Checks if any credential helper can be used on this system.
     *
     * @return bool
     */
    public function isSupported(): bool
    {
        if ($this->config->getBool('api.disable_credential_helpers')) {
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
    public function isInstalled(): bool
    {
        if (isset(self::$isInstalled)) {
            return self::$isInstalled;
        }

        try {
            $helper = $this->getHelper();
        } catch (\RuntimeException) {
            return self::$isInstalled = false;
        }

        $path = $this->getExecutablePath();

        return self::$isInstalled = $this->helperExists($helper, $path);
    }

    /**
     * Installs the credential helper.
     */
    public function install(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        $this->download($this->getHelper(), $this->getExecutablePath());

        self::$isInstalled = true;
    }

    /**
     * Stores a secret.
     */
    public function store(string $serverUrl, string $secret): void
    {
        $this->exec('store', json_encode([
            'ServerURL' => $serverUrl,
            'Username' => (string) (OsUtil::isWindows() ? getenv('USERNAME') : getenv('USER')),
            'Secret' => $secret,
        ]));
    }

    /**
     * Erases a secret.
     */
    public function erase(string $serverUrl): void
    {
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
     */
    public function get(string $serverUrl): string|false
    {
        try {
            $data = $this->exec('get', $serverUrl);
        } catch (ProcessFailedException $e) {
            if (stripos($e->getProcess()->getOutput(), 'credentials not found') !== false) {
                return false;
            }
            throw $e;
        }

        $json = json_decode($data, true);
        if ($json === null || !isset($json['Secret'])) {
            throw new \RuntimeException('Failed to decode JSON from credential helper');
        }

        return $json['Secret'];
    }

    /**
     * Lists all secrets.
     *
     * @return array<string, string>
     */
    public function listAll(): array
    {
        $data = $this->exec('list');

        return (array) json_decode($data, true);
    }

    /**
     * Verifies that a helper exists and is executable at a path.
     *
     * @param array{url: string, filename: string, sha256: string} $helper
     */
    private function helperExists(array $helper, string $path): bool
    {
        if (!file_exists($path) || !is_executable($path)) {
            return false;
        }

        return hash_file('sha256', $path) === $helper['sha256'];
    }

    /**
     * Downloads, extracts, and moves the credential helper to a destination.
     *
     * @param array{url: string, filename: string, sha256: string} $helper
     * @param string $destination
     */
    private function download(array $helper, string $destination): void
    {
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

        $fs = new Filesystem();

        // Work in a temporary file.
        $tmpFile = $destination . '-tmp';
        try {
            // Write the file, either directly or via extracting its archive.
            if (preg_match('/(\.tar\.gz|\.tgz|\.zip)$/', (string) $helper['url']) === 1) {
                $this->extractBinFromArchive($contents, str_ends_with((string) $helper['url'], '.zip'), $helper['filename'], $tmpFile);
            } else {
                $fs->dumpFile($tmpFile, $contents);
            }

            // Verify the file hash.
            $hash = hash_file('sha256', $tmpFile);
            if ($hash !== $helper['sha256']) {
                throw new \RuntimeException(sprintf('Failed to verify downloaded file for helper: %s (hash: %s, expected: %s)', $helper['url'], $hash, $helper['sha256']));
            }

            // Make the file executable and move it into place.
            $fs->chmod($tmpFile, 0o700);
            $fs->rename($tmpFile, $destination, true);
        } finally {
            $fs->remove($tmpFile);
        }
    }

    /**
     * Extracts the internal file from a package archive and moves it to a destination.
     *
     * @param string $archiveContents
     * @param bool   $zip
     * @param string $internalFilename
     * @param string $destination
     */
    private function extractBinFromArchive(string $archiveContents, bool $zip, string $internalFilename, string $destination): void
    {
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
                    $this->shell->mustExecute($command);
                } else {
                    throw new \RuntimeException('Failed to extract zip: unzip is not installed');
                }
            } else {
                $command = 'tar -xzp -f ' . escapeshellarg($tmpFile) . ' -C ' . escapeshellarg($tmpDir);
                $this->shell->mustExecute($command);
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
     * @return array<string, array<string, array{url: string, filename: string, sha256: string}>>
     */
    private function getHelpers(): array
    {
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
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-osxkeychain-v0.6.4-amd64.tar.gz',
                    'filename' => 'docker-credential-osxkeychain',
                    'sha256' => '76c4088359bbbcd25b8d0ff8436086742b6184aba6380ae57d39e5513f723b74',
                ],
                'arm64' => [
                    'url' => 'https://github.com/docker/docker-credential-helpers/releases/download/v0.6.4/docker-credential-osxkeychain-v0.6.4-arm64.tar.gz',
                    'filename' => 'docker-credential-osxkeychain',
                    'sha256' => '902e8237747aac0eca61efa1875e65aa8552b7c95fc406cf0d2aef733dda41de',
                ],
            ],
        ];
    }

    /**
     * Finds a helper package for this system.
     *
     * @return array{url: string, filename: string, sha256: string}
     */
    private function getHelper(): array
    {
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
            if ($this->shell->execute('ldconfig --print-cache | grep -q libsecret') === false) {
                throw new \RuntimeException('Unable to find a credentials helper for this system (libsecret is not installed)');
            }
        }

        if (str_ends_with($helpers[$os][$arch]['url'], '.zip') && !class_exists('\\ZipArchive') && !$this->shell->commandExists('unzip')) {
            throw new \RuntimeException('Unable to install a credentials helper for this system (it is a .zip file and the zip extension is unavailable)');
        }

        return $helpers[$os][$arch];
    }

    /**
     * Executes a command on the credential helper.
     */
    private function exec(string $command, mixed $input = null): string
    {
        $this->install();

        return $this->shell->mustExecute([$this->getExecutablePath(), $command], timeout: 10, input: $input);
    }

    /**
     * Finds the absolute path to the credential helper executable.
     *
     * @return string
     */
    private function getExecutablePath(): string
    {
        $path = $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . 'credential-helper';
        if (OsUtil::isWindows()) {
            $path .= '.exe';
        }

        return $path;
    }
}
