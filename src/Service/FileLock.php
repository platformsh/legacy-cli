<?php

namespace Platformsh\Cli\Service;

class FileLock
{
    private $config;

    private $checkIntervalMs;
    private $timeLimit;
    private $disabled;

    private $locks = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
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
            $content = $this->readWithLock($filename);
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
        $this->writeWithLock($filename, (string) \time());
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
            $this->writeWithLock($this->filename($lockName), '');
            unset($this->locks[$lockName]);
        }
    }

    /**
     * Destructor. Releases locks that still exist on exit.
     */
    public function __destruct()
    {
        foreach ($this->locks as $lockName) {
            $this->release($lockName);
        }
    }

    /**
     * Finds the filename for a lock.
     *
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

    /**
     * Reads a file using a shared lock.
     *
     * @param string $filename
     * @return string
     */
    private function readWithLock($filename)
    {
        $handle = \fopen($filename, 'r');
        if (!$handle) {
            throw new \RuntimeException('Failed to open file for reading: ' . $filename);
        }
        try {
            if (!\flock($handle, LOCK_SH)) {
                \trigger_error('Failed to lock file: ' . $filename, E_USER_WARNING);
            }
            $content = \fgets($handle);
            if ($content === false && !\feof($handle)) {
                throw new \RuntimeException('Failed to read file: ' . $filename);
            }
        } finally {
            if (!\flock($handle, LOCK_UN)) {
                \trigger_error('Failed to unlock file: ' . $filename, E_USER_WARNING);
            }
            if (!\fclose($handle)) {
                \trigger_error('Failed to close file: ' . $filename, E_USER_WARNING);
            }
        }
        return $content;
    }

    /**
     * Writes to a file using an exclusive lock.
     *
     * @param string $filename
     * @param string $content
     * @return void
     */
    private function writeWithLock($filename, $content)
    {
        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0777, true)) {
                throw new \RuntimeException('Failed to create directory: ' . $dir);
            }
        }
        $handle = \fopen($filename, 'w');
        if (!$handle) {
            throw new \RuntimeException('Failed to open file for writing: ' . $filename);
        }
        try {
            if (!\flock($handle, LOCK_EX)) {
                \trigger_error('Failed to lock file: ' . $filename, E_USER_WARNING);
            }
            if (\fputs($handle, $content) === false) {
                throw new \RuntimeException('Failed to write to file: ' . $filename);
            }
            if (PHP_VERSION_ID >= 81000) {
                if (!\fsync($handle)) {
                    \trigger_error('Failed to sync file (fsync): ' . $filename, E_USER_WARNING);
                }
            } elseif (!\fflush($handle)) {
                \trigger_error('Failed to flush file (fflush): ' . $filename, E_USER_WARNING);
            }
        } finally {
            if (!\flock($handle, LOCK_UN)) {
                \trigger_error('Failed to unlock file: ' . $filename, E_USER_WARNING);
            }
            if (!\fclose($handle)) {
                \trigger_error('Failed to close file: ' . $filename, E_USER_WARNING);
            }
        }
    }
}
