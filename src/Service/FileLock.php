<?php

namespace Platformsh\Cli\Service;

class FileLock
{
    private $config;
    private $fs;

    private $checkIntervalMs;
    private $timeLimit;
    private $disabled;

    private $locks = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->fs = new \Symfony\Component\Filesystem\Filesystem();
        $this->checkIntervalMs = 500;
        $this->timeLimit = 30;
        $this->disabled = (bool) $this->config->getWithDefault('api.disable_locks', false);
    }

    /**
     * Acquires a lock, or waits for one if it already exists.
     *
     * @param string $lockName
     *   A unique name for the lock.
     * @param callable|null $onWait
     *   A function to run when waiting starts.
     * @param callable|null $check
     *   A function to run each time the interval has passed. If it returns a
     *   non-null value, waiting will stop, and the value will be returned
     *   from this method.
     *
     * @return mixed|null
     */
    public function acquireOrWait($lockName, callable $onWait = null, callable $check = null)
    {
        if ($this->disabled) {
            return null;
        }
        $runOnWait = false;
        $filename = $this->filename($lockName);
        $start = \time();
        while (\time() - $start < $this->timeLimit) {
            if (!\file_exists($filename)) {
                break;
            }
            $content = \file_get_contents($filename);
            if ($content === false || $content === '') {
                break;
            }
            $lockedAt = \intval($content);
            if ($lockedAt === 0 || \time() >= $lockedAt + $this->timeLimit) {
                break;
            }
            if ($onWait !== null && !$runOnWait) {
                $onWait();
                $runOnWait = true;
            }
            \usleep($this->checkIntervalMs * 1000);
            if ($check !== null) {
                $result = $check();
                if ($result !== null) {
                    $this->release($lockName);
                    return $result;
                }
            }
        }
        $this->fs->dumpFile($filename, (string) \time());
        $this->locks[$lockName] = $lockName;
        return null;
    }

    /**
     * Releases a lock that was created by acquire().
     *
     * @param string $lockName
     */
    public function release($lockName)
    {
        if (!$this->disabled && isset($this->locks[$lockName])) {
            $this->fs->dumpFile($this->filename($lockName), '');
            unset($this->locks[$lockName]);
        }
    }

    /**
     * Destructor. Release locks that still exist on exit.
     */
    public function __destruct()
    {
        foreach ($this->locks as $lockName) {
            $this->release($lockName);
        }
    }

    /**
     * @param string $lockName
     * @return string
     */
    private function filename($lockName)
    {
        return $this->config->getWritableUserDir()
            . DIRECTORY_SEPARATOR . 'locks'
            . DIRECTORY_SEPARATOR
            . preg_replace('/[^\w_-]+/', '-', $lockName)
            . '.lock';
    }
}
