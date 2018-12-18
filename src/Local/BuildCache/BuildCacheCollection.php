<?php

namespace Platformsh\Cli\Local\BuildCache;

use Platformsh\Cli\Exception\InvalidConfigException;

class BuildCacheCollection implements \Iterator
{
    /** @var \Platformsh\Cli\Local\BuildCache\BuildCache[] */
    private $caches = [];

    /**
     * Get a list of cache configurations from an app's config.
     *
     * @param array $config
     *
     * @return \Platformsh\Cli\Local\BuildCache\BuildCacheCollection
     */
    public static function fromAppConfig(array $config)
    {
        $caches = new self();

        if (!empty($config['caches'])) {
            foreach ($config['caches'] as $name => $cache) {
                $caches->push(BuildCache::fromConfig($name, $cache));
            }
        }

        $caches->validate();

        return $caches;
    }

    /**
     * Add a cache to the list.
     *
     * @param \Platformsh\Cli\Local\BuildCache\BuildCache $cache
     */
    private function push(BuildCache $cache)
    {
        $this->caches[] = $cache;
    }

    /**
     * Validate the list of caches.
     *
     * @throws \InvalidArgumentException
     */
    private function validate()
    {
        $directories = array_map(function (BuildCache $cache) {
            return $cache->getDirectory();
        }, $this->caches);
        asort($directories);
        $lastDirectory = null;
        foreach ($directories as $key => $directory) {
            if ($lastDirectory !== null && strpos($directory, $lastDirectory) === 0) {
                throw new InvalidConfigException(sprintf('Cache directories cannot be nested (%s is inside %s)', $directory, $lastDirectory));
            }
            $lastDirectory = $directory;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return current($this->caches);
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        return next($this->caches);
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return key($this->caches);
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return current($this->caches) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        reset($this->caches);
    }
}
