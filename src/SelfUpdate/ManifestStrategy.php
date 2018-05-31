<?php
namespace Platformsh\Cli\SelfUpdate;

use Humbug\SelfUpdate\Exception\HttpRequestException;
use Humbug\SelfUpdate\Exception\JsonParsingException;
use Humbug\SelfUpdate\Strategy\StrategyInterface;
use Humbug\SelfUpdate\Updater;
use Humbug\SelfUpdate\VersionParser;

class ManifestStrategy implements StrategyInterface
{
    /** @var string */
    private $manifestUrl;

    /** @var array */
    private $manifest;

    /** @var array */
    private $availableVersions;

    /** @var string */
    private $localVersion;

    /** @var bool */
    private $allowMajor = false;

    /** @var bool */
    private $allowUnstable = false;

    /** @var array */
    private static $requiredKeys = ['sha256', 'version', 'url'];

    /** @var int */
    private $manifestTimeout = 10;

    /** @var int */
    private $downloadTimeout = 60;

    /** @var bool */
    private $ignorePhpReq = false;

    /**
     * ManifestStrategy constructor.
     *
     * @param string $localVersion  The local version.
     * @param string $manifestUrl   The URL to a JSON manifest file. The
     *                              manifest contains an array of objects, each
     *                              containing a 'version', 'sha256', and 'url'.
     * @param bool   $allowMajor    Whether to allow updating between major
     *                              versions.
     * @param bool   $allowUnstable Whether to allow updating to an unstable
     *                              version. Ignored if $localVersion is unstable
     *                              and there are no new stable versions.
     */
    public function __construct($localVersion, $manifestUrl, $allowMajor = false, $allowUnstable = false)
    {
        $this->localVersion = $localVersion;
        $this->manifestUrl = $manifestUrl;
        $this->allowMajor = $allowMajor;
        $this->allowUnstable = $allowUnstable;
    }

    /**
     * @param int $manifestTimeout
     */
    public function setManifestTimeout($manifestTimeout)
    {
        $this->manifestTimeout = $manifestTimeout;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentLocalVersion(Updater $updater)
    {
        return $this->localVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentRemoteVersion(Updater $updater)
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
     * Find update/upgrade notes for the new remote version.
     *
     * @param Updater $updater
     *
     * @return string|false
     *   A string if notes are found, or false otherwise.
     */
    public function getUpdateNotes(Updater $updater)
    {
        $versionInfo = $this->getRemoteVersionInfo($updater);
        if (empty($versionInfo['updating'])) {
            return false;
        }
        $localVersion = $this->getCurrentLocalVersion($updater);
        $items = isset($versionInfo['updating'][0]) ? $versionInfo['updating'] : [$versionInfo['updating']];
        foreach ($items as $updating) {
            if (!isset($updating['notes'])) {
                continue;
            } elseif (isset($updating['hide from']) && version_compare($localVersion, $updating['hide from'], '>=')) {
                continue;
            } elseif (isset($updating['show from']) && version_compare($localVersion, $updating['show from'], '<')) {
                continue;
            }
            return $updating['notes'];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function download(Updater $updater)
    {
        $versionInfo = $this->getRemoteVersionInfo($updater);

        $context = stream_context_create(['http' => ['timeout' => $this->downloadTimeout]]);
        $fileContents = file_get_contents($versionInfo['url'], false, $context);
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
                    $tmpSha
                )
            );
        }
    }

    /**
     * Get available versions to update to.
     *
     * @return array
     *   An array keyed by the version name, whose elements are arrays
     *   containing version information ('version', 'sha256', and 'url').
     */
    private function getAvailableVersions()
    {
        if (!isset($this->availableVersions)) {
            $this->availableVersions = [];
            foreach ($this->getManifest() as $key => $item) {
                if ($missing = array_diff(self::$requiredKeys, array_keys($item))) {
                    throw new \RuntimeException(sprintf(
                        'Manifest item %s missing required key(s): %s',
                        $key,
                        implode(',', $missing)
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
     * @return array
     */
    private function getRemoteVersionInfo(Updater $updater)
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
     * Download and decode the JSON manifest file.
     *
     * @return array
     */
    private function getManifest()
    {
        if (!isset($this->manifest)) {
            $context = stream_context_create(['http' => ['timeout' => $this->manifestTimeout]]);
            $manifestContents = file_get_contents($this->manifestUrl, false, $context);
            if ($manifestContents === false) {
                throw new \RuntimeException(sprintf('Failed to download manifest: %s', $this->manifestUrl));
            }

            $this->manifest = (array) json_decode($manifestContents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new JsonParsingException(
                    'Error parsing manifest file'
                    . (function_exists('json_last_error_msg') ? ': ' . json_last_error_msg() : '')
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
    private function filterByLocalMajorVersion(array $versions)
    {
        list($localMajorVersion, ) = explode('.', $this->localVersion, 2);

        return array_filter($versions, function ($version) use ($localMajorVersion) {
            list($majorVersion, ) = explode('.', $version, 2);
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
    private function filterByPhpVersion(array $versions)
    {
        $versionInfo = $this->getAvailableVersions();

        return array_filter($versions, function ($version) use ($versionInfo) {
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
