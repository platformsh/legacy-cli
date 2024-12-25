<?php

declare(strict_types=1);

namespace Platformsh\Cli\SelfUpdate;

use Humbug\SelfUpdate\Exception\HttpRequestException;
use Humbug\SelfUpdate\Exception\JsonParsingException;
use Humbug\SelfUpdate\Strategy\StrategyInterface;
use Humbug\SelfUpdate\Updater;
use Humbug\SelfUpdate\VersionParser;

class ManifestStrategy implements StrategyInterface
{
    /** @var array<mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, array<mixed>>|null */
    private ?array $availableVersions = null;

    private const REQUIRED_KEYS = ['sha256', 'version', 'url'];

    private int $manifestTimeout = 10;

    private int $downloadTimeout = 60;

    private bool $ignorePhpReq = false;

    /** @var array<string, mixed> */
    private array $streamContextOptions = [];

    /**
     * ManifestStrategy constructor.
     *
     * @param string $localVersion The local version.
     * @param string $manifestUrl The URL to a JSON manifest file. The
     *                            manifest contains an array of objects, each
     *                            containing a 'version', 'sha256', and 'url'.
     * @param bool $allowMajor Whether to allow updating between major
     *                         versions.
     * @param bool $allowUnstable Whether to allow updating to an unstable
     *                            version. Ignored if $localVersion is unstable
     *                            and there are no new stable versions.
     */
    public function __construct(private readonly string $localVersion, private readonly string $manifestUrl, private readonly bool $allowMajor = false, private readonly bool $allowUnstable = false) {}

    /**
     * @param array<string, mixed> $opts
     */
    public function setStreamContextOptions(array $opts): void
    {
        $this->streamContextOptions = $opts;
    }

    public function setManifestTimeout(int $manifestTimeout): void
    {
        $this->manifestTimeout = $manifestTimeout;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentLocalVersion(Updater $updater): string
    {
        return $this->localVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentRemoteVersion(Updater $updater): bool|string
    {
        $versions = array_keys($this->getAvailableVersions());
        if (!$this->allowMajor) {
            $versions = $this->filterByLocalMajorVersion($versions);
        }
        if (!$this->ignorePhpReq) {
            $versions = $this->filterByPhpVersion($versions);
        }

        $versionParser = new VersionParser($versions);

        $mostRecent = $versionParser->getMostRecentStable();

        // Look for unstable updates if explicitly allowed, or if the local
        // version is already unstable and there is no new stable version.
        if ($this->allowUnstable
            || ($versionParser->isUnstable($this->localVersion)
                && version_compare($mostRecent, $this->localVersion, '<'))) {
            $mostRecent = $versionParser->getMostRecentAll();
        }

        return version_compare($mostRecent, $this->localVersion, '>') ? $mostRecent : false;
    }

    /**
     * Finds update/upgrade notes for the new remote version.
     *
     * @return array<string, string>
     */
    public function getUpdateNotesByVersion(string $currentVersion, string $targetVersion): array
    {
        $notes = [];
        foreach ($this->getAvailableVersions() as $version => $item) {
            if (isset($item['notes']) && \version_compare($version, $currentVersion, '>') && \version_compare($version, $targetVersion, '<=')) {
                if (is_array($item['notes'])) {
                    foreach ($item['notes'] as $notesVersion => $notesString) {
                        if (\version_compare($notesVersion, $currentVersion, '>') && \version_compare($notesVersion, $targetVersion, '<=')) {
                            $notes[$notesVersion] = $notesString;
                        }
                    }
                } else {
                    $notes[$version] = $item['notes'];
                }
            }
        }
        uksort($notes, fn($a, $b): int => \version_compare($a, $b));
        return $notes;
    }

    /**
     * {@inheritdoc}
     */
    public function download(Updater $updater): void
    {
        $versionInfo = $this->getRemoteVersionInfo($updater);

        // A relative download URL is treated as relative to the manifest URL.
        $url = $versionInfo['url'];
        if (!str_contains((string) $url, '//') && str_contains($this->manifestUrl, '//')) {
            $removePath = (string) parse_url($this->manifestUrl, PHP_URL_PATH);
            $url = str_replace($removePath, '/' . ltrim((string) $url, '/'), $this->manifestUrl);
        }

        $opts = $this->streamContextOptions;
        $opts['http']['timeout'] = $this->downloadTimeout;
        $fileContents = file_get_contents($url, false, stream_context_create($opts));
        if ($fileContents === false) {
            throw new HttpRequestException(sprintf('Failed to download file from URL: %s', $versionInfo['url']));
        }

        $tmpFilename = $updater->getTempPharFile();
        if (file_put_contents($tmpFilename, $fileContents) === false) {
            throw new \RuntimeException(sprintf('Failed to write file: %s', $tmpFilename));
        }

        $tmpSha = hash_file('sha256', $tmpFilename);
        if ($tmpSha !== $versionInfo['sha256']) {
            unlink($tmpFilename);
            throw new \RuntimeException(
                sprintf(
                    'SHA-256 verification failed: expected %s, actual %s',
                    $versionInfo['sha256'],
                    $tmpSha,
                ),
            );
        }
    }

    /**
     * Get available versions to update to.
     *
     * @return array<string, array{
     *     version: string,
     *     sha256: string,
     *     url: string,
     *     php?: array{
     *       min?: string,
     *       max?: string
     *     },
     *     notes?: string|array<string, string>
     *   }>
     *   An array keyed by the version name, whose elements are arrays
     *   containing version information.
     */
    private function getAvailableVersions(): array
    {
        if (!isset($this->availableVersions)) {
            $this->availableVersions = [];
            foreach ($this->getManifest() as $key => $item) {
                if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($item))) {
                    throw new \RuntimeException(sprintf(
                        'Manifest item %s missing required key(s): %s',
                        $key,
                        implode(',', $missing),
                    ));
                }
                $this->availableVersions[$item['version']] = $item;
            }
        }

        return $this->availableVersions;
    }

    /**
     * Get version information for the latest remote version.
     *
     * @param Updater $updater
     *
     * @return array<string, mixed>
     */
    private function getRemoteVersionInfo(Updater $updater): array
    {
        $version = $this->getCurrentRemoteVersion($updater);
        if ($version === false) {
            throw new \RuntimeException('No remote versions found');
        }
        $versionInfo = $this->getAvailableVersions();
        if (!isset($versionInfo[$version])) {
            throw new \RuntimeException(sprintf('Failed to find manifest item for version %s', $version));
        }

        return $versionInfo[$version];
    }

    /**
     * Downloads and decodes the JSON manifest file.
     *
     * @return array<mixed>
     */
    private function getManifest(): array
    {
        if (!isset($this->manifest)) {
            $opts = $this->streamContextOptions;
            $opts['http']['timeout'] = $this->manifestTimeout;
            $manifestContents = file_get_contents($this->manifestUrl, false, stream_context_create($opts));
            if ($manifestContents === false) {
                throw new \RuntimeException(sprintf('Failed to download manifest: %s', $this->manifestUrl));
            }

            $this->manifest = (array) json_decode($manifestContents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new JsonParsingException(
                    'Error parsing manifest file'
                    . (function_exists('json_last_error_msg') ? ': ' . json_last_error_msg() : ''),
                );
            }
        }

        return $this->manifest;
    }

    /**
     * Filter a list of versions to those that match the current local version.
     *
     * @param string[] $versions
     *
     * @return string[]
     */
    private function filterByLocalMajorVersion(array $versions): array
    {
        [$localMajorVersion, ] = explode('.', $this->localVersion, 2);

        return array_filter($versions, function ($version) use ($localMajorVersion): bool {
            [$majorVersion, ] = explode('.', $version, 2);
            return $majorVersion === $localMajorVersion;
        });
    }

    /**
     * Filter a list of versions to those that allow the current PHP version.
     *
     * @param string[] $versions
     *
     * @return string[]
     */
    private function filterByPhpVersion(array $versions): array
    {
        $versionInfo = $this->getAvailableVersions();

        return array_filter($versions, function ($version) use ($versionInfo): bool {
            if (isset($versionInfo[$version]['php']['min'])
                && version_compare(PHP_VERSION, $versionInfo[$version]['php']['min'], '<')) {
                return false;
            } elseif (isset($versionInfo[$version]['php']['max'])
                && version_compare(PHP_VERSION, $versionInfo[$version]['php']['max'], '>')) {
                return false;
            }

            return true;
        });
    }
}
