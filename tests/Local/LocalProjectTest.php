<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalProject;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class LocalProjectTest extends \PHPUnit_Framework_TestCase
{

    /** @var vfsStreamDirectory */
    protected $root;

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $this->root = vfsStream::setup(__CLASS__);
    }

    public function testGetProjectRoot()
    {
        $tempDir = $this->root->getName();
        $testDir = tempnam($tempDir, '');
        unlink($testDir);
        mkdir("$testDir/1/2/3/4/5", 0755, true);

        $expectedRoot = "$testDir/1";
        touch("$expectedRoot/.platform-project");

        chdir($testDir);
        $this->assertFalse(LocalProject::getProjectRoot());

        chdir($expectedRoot);
        $this->assertEquals($expectedRoot, LocalProject::getProjectRoot());

        chdir("$testDir/1/2/3/4/5");
        $this->assertEquals($expectedRoot, LocalProject::getProjectRoot());
    }

}
