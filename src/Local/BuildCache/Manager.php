<?php

namespace Platformsh\Cli\Local\BuildCache;

use Platformsh\Cli\Service\Filesystem;

class Manager
{
    private $fs;
    private $cacheDir;
    private $cacheKeys = [];

    public function __construct($cacheDir, Filesystem $fs = null)
    {
        $this->fs = $fs ?: new Filesystem();
        $this->setCacheDir($cacheDir);
    }

    /**
     * Set the cache directory.
     *
     * @param string $cacheDir
     */
    private function setCacheDir($cacheDir)
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true)) {
            throw new \InvalidArgumentException("Cache directory not found: $cacheDir");
        }
        if (!is_writable($cacheDir)) {
            throw new \InvalidArgumentException("The cache directory is not writable: $cacheDir");
        }
        $this->cacheDir = $cacheDir;
    }

    /**
     * Restore from a cache.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     *   The cache configuration.
     * @param string                                      $sourceDir
     *   The absolute path to the source code directory.
     * @param string                                      $buildDir
     *   The absolute path to the build directory.
     * @param string|NULL                                 $archive
     *   The absolute filename of an archive, or NULL to find an archive.
     *
     * @return bool
     *  True if a cache was restored, false otherwise.
     */
    public function restore(BuildCache $cache, $sourceDir, $buildDir, $archive = null)
    {
        if ($archive === null) {
            $archive = $this->findArchive($cache, $sourceDir, false);
        }
        if (!$archive) {
            return false;
        }

        if (!is_dir($buildDir)) {
            throw new \InvalidArgumentException("Build directory not found: $buildDir");
        }
        if (!is_writable($buildDir)) {
            throw new \InvalidArgumentException("The build directory is not writable: $buildDir");
        }

        $destination = $buildDir . DIRECTORY_SEPARATOR . $cache->getDirectory();
        $this->fs->extractArchive($archive, $destination);

        return true;
    }

    /**
     * Save to the cache after build.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     * @param string                                      $sourceDir
     * @param string                                      $buildDir
     */
    public function save(BuildCache $cache, $sourceDir, $buildDir)
    {
        $built = $buildDir . DIRECTORY_SEPARATOR . $cache->getDirectory();
        if (!file_exists($built)) {
            throw new \RuntimeException("Cache directory not found: $built");
        }
        if (!is_dir($built)) {
            throw new \RuntimeException("Cache 'directory' is not a directory: $built");
        }
        $cacheKey = $this->getCacheKey($cache, $sourceDir);
        $filename = $this->getFilename($cache, $cacheKey);
        if (!is_dir(dirname($filename))) {
            $this->fs->mkdir(dirname($filename), 0700);
        }
        $this->fs->archiveDir($built, $filename);
    }

    /**
     * Find an existing archive for a cache.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     * @param string                                      $sourceDir
     * @param bool                                        $exact
     *
     * @return string|false
     *   The absolute filename of the archive to restore, or false if no archive exists.
     */
    public function findArchive(BuildCache $cache, $sourceDir, $exact = false)
    {
        $cacheKey = $this->getCacheKey($cache, $sourceDir);
        $filename = $this->getFilename($cache, $cacheKey);
        if (file_exists($filename)) {
            return $filename;
        }
        if ($exact || !$cache->allowStale()) {
            return false;
        }

        $subDirectory = $this->getSubdirectory($cache);
        if (is_dir($subDirectory) && ($files = scandir($subDirectory))) {
            $files = array_filter($files, function ($filename) {
                return $filename !== '.' && $filename !== '..';
            });
            $files = array_map(function ($filename) use ($subDirectory) {
                return $subDirectory . DIRECTORY_SEPARATOR . $filename;
            }, $files);
            usort($files, function ($a, $b) use ($subDirectory) {
                return filemtime($a) - filemtime($b);
            });

            return reset($files);
        }

        return false;
    }

    /**
     * Get the subdirectory under which cache files will be stored.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     *
     * @return string
     */
    private function getSubdirectory(BuildCache $cache)
    {
        $path = $this->cacheDir;
        $appName = $cache->canShareBetweenApps() ? '_shared' : $cache->getAppName();
        if (!empty($appName)) {
            $path .= DIRECTORY_SEPARATOR . trim($appName, '/\\');
        }
        $path .= DIRECTORY_SEPARATOR . trim($cache->getDirectory(), '/\\');

        return $path;
    }

    /**
     * Get the filename for a cache file.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     * @param string                                      $cacheKey
     *
     * @return string
     */
    private function getFilename(BuildCache $cache, $cacheKey)
    {
        return $this->getSubdirectory($cache) . DIRECTORY_SEPARATOR . $cacheKey . '.tar.gz';
    }

    /**
     * Generate a cache key for a cache and a source directory.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     * @param string                                      $sourceDir
     *
     * @return string
     */
    private function getCacheKey(BuildCache $cache, $sourceDir)
    {
        if (!is_dir($sourceDir)) {
            throw new \InvalidArgumentException("Source directory not found: $sourceDir");
        }
        $name = $cache->getName();
        if (isset($this->cacheKeys[$name])) {
            return $this->cacheKeys[$name];
        }

        $hashes = [];
        foreach ($cache->getWatchedPaths() as $watchedPath) {
            foreach (glob($sourceDir . '/' . ltrim($watchedPath, '/\\')) as $path) {
                $hashes[$path] = sha1_file($path);
            }
        }
        ksort($hashes);

        $cacheKeys[$name] = $cacheKey = hash('sha256', $name . ':' . implode(':', $hashes));

        return $cacheKey;
    }

    /**
     * Deletes all (current and stale) archives.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache|null $cache
     */
    public function deleteAll(BuildCache $cache = null)
    {
        $this->fs->remove($cache ? $this->getSubdirectory($cache) : $this->cacheDir);
    }
}
