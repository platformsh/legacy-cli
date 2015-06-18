<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\ConsoleOutput;

class VanillaTest extends BaseToolstackTest
{

    public function testBuildVanilla()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/vanilla');
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $this->assertFileExists($webRoot . '/index.html');
    }

    /**
     * Test building without symlinks.
     */
    public function testBuildNoSymlinks()
    {
        $builder = new LocalBuild(array('copy' => true), new ConsoleOutput());
        $sourceDir = 'tests/data/apps/vanilla';
        $projectRoot = $this->createDummyProject($sourceDir);
        $success = $builder->buildProject($projectRoot);
        $this->assertTrue($success, 'Build success for dir: ' . $sourceDir);
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $this->assertFileExists($webRoot . '/index.html');
        $this->assertTrue(is_dir($webRoot), 'Web root is an actual directory');
    }

    /**
     * Test with a custom source and destination.
     */
    public function testBuildCustomSourceDestination()
    {
        // N.B. the source directory and destination must be absolute for this
        // to work.
        $sourceDir = realpath('tests/data/apps/vanilla');

        $tempDir = self::$root->getName();
        $destination = tempnam($tempDir, '');

        // Test with symlinking.
        $builder = new LocalBuild(array(), self::$output);
        $builder->build($sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Test with copying.
        $builder = new LocalBuild(array('copy' => true), self::$output);
        $builder->build($sourceDir, $destination);
        $this->assertFileExists($destination . '/index.html');

        // Remove the builds directory.
        exec('rm -R ' . escapeshellarg($sourceDir . '/' . LocalProject::BUILD_DIR));
    }

    /**
     * Test with a custom destination.
     */
    public function testBuildCustomDestination()
    {
        $projectRoot = $this->createDummyProject('tests/data/apps/vanilla');

        $destination = $projectRoot . '/web';

        $builder = new LocalBuild(array(), self::$output);
        $builder->buildProject($projectRoot, null, $destination);
        $this->assertFileExists($destination . '/index.html');
    }
}
