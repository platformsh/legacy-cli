<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Tests\HasTempDirTrait;

class FilesystemServiceTest extends TestCase
{
    use HasTempDirTrait;

    protected Filesystem $fs;

    /**
     * @{inheritdoc}
     */
    public function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tempDirSetUp();
    }

    /**
     * Test our own self::tempDir().
     */
    public function testTempDir(): void
    {
        $tempDir = $this->tempDir();
        $this->assertTrue(is_dir($tempDir));
        $tempDir = $this->tempDir(true);
        $this->assertFileExists($tempDir . '/test-file');
        $this->assertFileExists($tempDir . '/test-dir/test-file');
        $this->assertFileExists($tempDir . '/test-nesting/1/2/3/test-file');
    }

    /**
     * Test FilesystemHelper::remove() on directories.
     */
    public function testRemoveDir(): void
    {
        // Create a test directory containing some files in several levels.
        $testDir = $this->tempDir(true);

        // Check that the directory can be removed.
        $this->assertTrue($this->fs->remove($testDir));
        $this->assertFileDoesNotExist($testDir);
    }

    /**
     * Test FilesystemHelper::copyAll().
     */
    public function testCopyAll(): void
    {
        $source = $this->tempDir(true);
        $destination = $this->tempDir();
        touch($source . '/.donotcopy');

        // Copy files.
        $this->fs->copyAll($source, $destination, ['.*']);

        // Check that they have been copied.
        $this->assertFileExists($destination . '/test-file');
        $this->assertFileExists($destination . '/test-dir/test-file');
        $this->assertFileDoesNotExist($destination . '/.donotcopy');
    }

    /**
     * Test FilesystemHelper::symlinkDir().
     */
    public function testSymlinkDir(): void
    {
        $testTarget = $this->tempDir();
        $testLink = $this->tempDir() . '/link';
        $this->fs->symlink($testTarget, $testLink);
        $this->assertTrue(is_link($testLink));
        touch($testTarget . '/test-file');
        $this->assertFileExists($testLink . '/test-file');
    }

    /**
     * Test FilesystemHelper::makePathAbsolute().
     */
    public function testMakePathAbsolute(): void
    {
        $testDir = $this->tempDir();
        chdir($testDir);

        $path = $this->fs->makePathAbsolute('test.txt');
        $this->assertEquals($testDir . '/' . 'test.txt', $path);

        $childDir = $testDir . '/test';
        mkdir($childDir);
        chdir($childDir);

        $path = $this->fs->makePathAbsolute('../test.txt');
        $this->assertEquals($testDir . '/' . 'test.txt', $path);

        $path = $this->fs->makePathAbsolute('..');
        $this->assertEquals($testDir, $path);

        $this->expectException('InvalidArgumentException');
        $this->fs->makePathAbsolute('nonexistent/test.txt');
    }

    /**
     * Test FilesystemHelper::symlinkAll().
     */
    public function testSymlinkAll(): void
    {
        $testSource = $this->tempDir(true);
        $testDestination = $this->tempDir();

        // Test plain symlinking.
        $this->fs->symlinkAll($testSource, $testDestination);

        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with skipping an existing file.
        $testDestination = $this->tempDir();
        touch($testDestination . '/test-file');
        $this->fs->symlinkAll($testSource, $testDestination);
        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with relative links. This has no effect on Windows.
        $testDestination = $this->tempDir();
        $this->fs->setRelativeLinks();
        $this->fs->symlinkAll($testSource, $testDestination);
        $this->fs->setRelativeLinks(false);
        $this->assertFileExists($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');

        // Test with a list of excluded files.
        $testDestination = $this->tempDir();
        touch($testSource . '/test-file2');
        $this->fs->symlinkAll($testSource, $testDestination, true, false, ['test-file']);
        $this->assertFileDoesNotExist($testDestination . '/test-file');
        $this->assertFileExists($testDestination . '/test-dir/test-file');
        $this->assertFileExists($testDestination . '/test-nesting/1/2/3/test-file');
    }

    public function testCanWrite(): void
    {
        \umask(0o002);

        $testDir = $this->createTempSubDir();
        if (touch($testDir . '/test-file')) {
            $this->assertTrue($this->fs->canWrite($testDir . '/test-file'));
        } else {
            $this->markTestIncomplete('Failed to create file: ' . $testDir . '/test-file');
        }

        chmod($testDir . '/test-file', 0o500);
        $this->assertEquals(\is_writable($testDir . '/test-file'), $this->fs->canWrite($testDir . '/test-file'));

        if (mkdir($testDir . '/test-dir', 0o700)) {
            $this->assertTrue($this->fs->canWrite($testDir . '/test-dir'));
            $this->assertTrue($this->fs->canWrite($testDir . '/test-dir/1'));
            $this->assertTrue($this->fs->canWrite($testDir . '/test-dir/1/2/3'));
        } else {
            $this->markTestIncomplete('Failed to create directory: ' . $testDir . '/test-dir');
        }

        if (mkdir($testDir . '/test-ro-dir', 0o500)) {
            $this->assertEquals(is_writable($testDir . '/test-ro-dir'), $this->fs->canWrite($testDir . '/test-ro-dir'));
            $this->assertEquals(is_writable($testDir . '/test-ro-dir'), $this->fs->canWrite($testDir . '/test-ro-dir/1'));
        } else {
            $this->markTestIncomplete('Failed to create directory: ' . $testDir . '/test-ro-dir');
        }
    }

    /**
     * Create a test directory with a unique name.
     *
     * @param bool $fill Fill the directory with some files.
     *
     * @return string
     */
    protected function tempDir(?bool $fill = false): string
    {
        $testDir = $this->createTempSubDir();
        if ($fill) {
            touch($testDir . '/test-file');
            mkdir($testDir . '/test-dir');
            touch($testDir . '/test-dir/test-file');
            mkdir($testDir . '/test-nesting/1/2/3', 0o755, true);
            touch($testDir . '/test-nesting/1/2/3/test-file');
        }

        return $testDir;
    }

}
