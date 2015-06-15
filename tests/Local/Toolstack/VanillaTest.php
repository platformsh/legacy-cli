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
}
