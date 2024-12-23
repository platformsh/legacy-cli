<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

class FileLock
{
    private readonly int $checkIntervalMs;
    private readonly int $timeLimit;
    private readonly bool $disabled;

    /** @var array<string, string> */
    private array $locks = [];

    public function __construct(private readonly Config $config)
    {
        $this->checkIntervalMs = 500;
        $this->timeLimit = 30;
        $this->disabled = $this->config->getBool('api.disable_locks');
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
    public function acquireOrWait(string $lockName, ?callable $onWait = null, ?callable $check = null): mixed
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
     */
    public function release(string $lockName): void
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
     */
    private function filename(string $lockName): string
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
    private function readWithLock(string $filename): string
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
        return (string) $content;
    }

    /**
     * Writes to a file using an exclusive lock.
     *
     * @param string $filename
     * @param string $content
     * @return void
     */
    private function writeWithLock(string $filename, string $content): void
    {
        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0o777, true)) {
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
            if (!\fsync($handle)) {
                \trigger_error('Failed to sync file (fsync): ' . $filename, E_USER_WARNING);
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
