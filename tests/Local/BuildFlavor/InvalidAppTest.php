<?php

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use Platformsh\Cli\Exception\InvalidConfigException;

class InvalidAppTest extends BuildFlavorTestBase
{
    public function testNoAppConfigThrowsException()
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Configuration file not found');
        $this->assertBuildSucceeds('tests/data/apps/invalid', [], false);
    }
}
