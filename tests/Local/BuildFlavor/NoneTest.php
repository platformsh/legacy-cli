<?php

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class NoneTest extends BuildFlavorTestBase
{
    public function testBuildNone(): void
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/none');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.html');
    }
}
