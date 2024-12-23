<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Tests\HasTempDirTrait;

class LocalProjectTest extends TestCase
{
    use HasTempDirTrait;

    public function testGetLegacyProjectRoot(): void
    {
        $this->tempDirSetUp();
        $testDir = $this->tempDir;
        mkdir("$testDir/1/2/3/4/5", 0o755, true);

        $expectedRoot = "$testDir/1";
        $config = new Config();
        $this->assertTrue($config->has('local.project_config_legacy'));
        touch("$expectedRoot/" . $config->getStr('local.project_config_legacy'));

        chdir($testDir);
        $localProject = new LocalProject();
        $this->assertFalse($localProject->getProjectRoot());
        $this->assertFalse($localProject->getLegacyProjectRoot());

        chdir($expectedRoot);
        $this->assertFalse($localProject->getProjectRoot());
        $this->assertEquals($expectedRoot, $localProject->getLegacyProjectRoot());

        chdir("$testDir/1/2/3/4/5");
        $this->assertFalse($localProject->getProjectRoot());
        $this->assertEquals($expectedRoot, $localProject->getLegacyProjectRoot());
    }
}
