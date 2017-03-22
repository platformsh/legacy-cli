<?php

namespace Platformsh\Cli\Tests\BuildFlavor;

class NoneTest extends BaseBuildFlavorTest
{
    public function testBuildNone()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/none');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }
}
