<?php

namespace Platformsh\Cli\Tests\BuildFlavor;

use Platformsh\Cli\Exception\InvalidConfigException;

class InvalidAppTest extends BaseBuildFlavorTest
{
    public function testNoAppConfigThrowsException()
    {
        $this->setExpectedException(InvalidConfigException::class, 'Configuration file not found');
        $this->assertBuildSucceeds('tests/data/apps/invalid', [], false);
    }
}
