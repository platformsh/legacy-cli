<?php

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

/**
 * @group slow
 */
class NodeJsTest extends BuildFlavorTestBase
{
    public function testBuildNodeJs(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs');
    }

    public function testBuildNodeJsCopy(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/nodejs', ['copy' => true]);
    }
}
