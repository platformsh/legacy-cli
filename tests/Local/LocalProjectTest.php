<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Local\LocalProject;
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
        $root = LocalProject::getProjectRoot();
        $this->assertEquals(false, $root);

        chdir($expectedRoot);
        $root = LocalProject::getProjectRoot();
        $this->assertEquals($expectedRoot, $root);

        chdir("$testDir/1/2/3/4/5");
        $root = LocalProject::getProjectRoot();
        $this->assertEquals($expectedRoot, $root);
    }

}
