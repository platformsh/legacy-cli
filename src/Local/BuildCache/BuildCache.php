<?php

namespace Platformsh\Cli\Local\BuildCache;

use Platformsh\Cli\Exception\InvalidConfigException;

class BuildCache
{
    private $name;
    private $appName;
    private $directory;
    private $watch = [];
    private $allowStale = false;
    private $shareBetweenApps = false;

    /**
     * BuildCache constructor.
     *
     * @param string $name
     * @param array  $config
     */
    private function __construct($name, $appName, array $config)
    {
        $config += [
            'directory' => null,
            'allow_stale' => false,
            'share_between_apps' => false,
        ];
        foreach (['allow_stale', 'share_between_apps'] as $key) {
            if (!is_bool($config[$key])) {
                throw new InvalidConfigException("$key must be a Boolean (true or false)");
            }
        }
        if (!isset($config['watch'])) {
            throw new InvalidConfigException("'watch' is required in cache configuration");
        }

        $this->name = $name;
        $this->appName = $appName;
        $this->directory = $config['directory'];
        $this->allowStale = $config['allow_stale'];
        $this->shareBetweenApps = $config['share_between_apps'];
        $this->watch = (array) $config['watch'];
    }

    /**
     * Instantiate a BuildCache from a config array.
     *
     * @param string $name
     * @param string $appName
     * @param array  $config
     *
     * @return \Platformsh\Cli\Local\BuildCache\BuildCache
     */
    public static function fromConfig($name, $appName, array $config)
    {
        if (empty($name) || !is_string($name)) {
            throw new InvalidConfigException('The cache name must be a non-empty string.');
        }

        return new self($name, $appName, $config);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getAppName()
    {
        return $this->appName;
    }

    /**
     * @return string[]
     */
    public function getWatchedPaths()
    {
        return $this->watch;
    }

    /**
     * @return string
     */
    public function getDirectory()
    {
        return ltrim($this->directory ?: $this->name, '/\\');
    }

    /**
     * @return bool
     */
    public function allowStale()
    {
        return $this->allowStale;
    }

    /**
     * @return bool
     */
    public function canShareBetweenApps()
    {
        return $this->shareBetweenApps;
    }
}
