<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalBuild;

class VanillaTest extends BaseToolstackTest
{
    public function testBuildVanilla()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }

    /**
     * Test building without symlinks.
     */
    public function testBuildNoSymlinks()
    {
        $sourceDir = 'tests/data/apps/vanilla';
        $projectRoot = $this->assertBuildSucceeds($sourceDir, ['copy' => true]);
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
        $this->assertTrue(is_dir($webRoot), 'Web root is an actual directory');
    }

    /**
     * Test building with a custom web root.
     */
    public function testBuildCustomWebRoot()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla-webroot');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla-webroot', ['copy' => true]);
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }

    /**
     * Test with a custom source and destination.
     */
    public function testBuildCustomSourceDestination()
    {
        // Copy the 'vanilla' app to a temporary directory.
        $sourceDir = $this->createTempSubDir();
        $fsHelper = new FilesystemHelper();
        $fsHelper->copyAll('tests/data/apps/vanilla', $sourceDir);

        // Create another temporary directory.
        $destination = $this->createTempSubDir();

        // Test with symlinking.
        $builder = new LocalBuild(['abslinks' => true], null, self::$output);
        $builder->build($sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Test with copying.
        $builder = new LocalBuild(['copy' => true, 'abslinks' => true], null, self::$output);
        $builder->build($sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Remove the temporary files.
        exec('rm -R ' . escapeshellarg($destination) . ' ' . escapeshellarg($sourceDir));
    }

    /**
     * Test with a custom destination.
     */
    public function testBuildCustomDestination()
    {
        $projectRoot = $this->createDummyProject('tests/data/apps/vanilla');

        $destination = $projectRoot . '/web';

        $builder = new LocalBuild($this->buildSettings, null, self::$output);
        $builder->build($projectRoot, $destination);
        $this->assertFileExists($destination . '/index.html');
    }
}
