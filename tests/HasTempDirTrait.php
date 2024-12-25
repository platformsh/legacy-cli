<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests;

trait HasTempDirTrait
{
    protected ?string $tempDir;

    protected function tempDirSetUp(): void
    {
        if (!isset($this->tempDir)) {
            $this->tempDir = $this->createTempDir(sys_get_temp_dir(), 'pshCliTmp');
        }
    }

    /**
     * @param string $parentDir
     * @param string $prefix
     *
     * @return string
     */
    protected function createTempDir(string $parentDir, string $prefix = ''): string
    {
        if (!($tempDir = tempnam($parentDir, $prefix))
          || !unlink($tempDir)
          || !mkdir($tempDir, 0o755)) {
            throw new \RuntimeException('Failed to create temporary directory in: ' . $parentDir);
        }

        return $tempDir;
    }

    /**
     * @param string $prefix
     *
     * @return string
     */
    protected function createTempSubDir(string $prefix = ''): string
    {
        $this->tempDirSetUp();

        return $this->createTempDir($this->tempDir, $prefix);
    }

    public function tearDown(): void
    {
        if (!empty($this->tempDir)) {
            exec('rm -Rf ' . escapeshellarg($this->tempDir));
        }
    }
}
