<?php

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

/**
 * @group slow
 */
class NodeJsTest extends BuildFlavorTestBase
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
