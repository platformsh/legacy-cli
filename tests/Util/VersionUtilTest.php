<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\VersionUtil;

class VersionUtilTest extends \PHPUnit_Framework_TestCase
{
    public function testSimple()
    {
        $util = new VersionUtil();
        $this->assertEquals(['1.0.1', '1.1.0', '2.0.0'], $util->nextVersions('1.0.0'));
        $this->assertEquals(['1.0.2', '1.1.0', '2.0.0'], $util->nextVersions('1.0.1'));
        $this->assertEquals(['2.0.3-beta', '2.1.0-beta', '3.0.0-beta'], $util->nextVersions('2.0.2-beta'));
    }
}
