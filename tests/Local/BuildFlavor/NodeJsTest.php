<?php

namespace Platformsh\Cli\Tests\BuildFlavor;

class NodeJsTest extends BaseBuildFlavorTest
{
    public function testBuildNodeJs()
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs');
    }

    public function testBuildNodeJsCopy()
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs', ['copy' => true]);
    }
}
