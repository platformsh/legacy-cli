<?php

namespace Platformsh\Cli\Tests\Toolstack;

class NodeJsTest extends BaseToolstackTest
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
