<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\VersionUtil;

class VersionUtilTest extends \PHPUnit_Framework_TestCase
{
    public function testNextVersions()
    {
        $util = new VersionUtil();
        $this->assertEquals(['1.0.1', '1.1.0', '2.0.0'], $util->nextVersions('1.0.0'));
        $this->assertEquals(['1.0.2', '1.1.0', '2.0.0'], $util->nextVersions('1.0.1'));
        $this->assertEquals(['2.0.3-beta', '2.1.0-beta', '3.0.0-beta'], $util->nextVersions('2.0.2-beta'));
    }

    public function testIsMajorOrMinor()
    {
        $util = new VersionUtil();
        $this->assertTrue($util->isMajorOrMinor('1.0.0'));
        $this->assertTrue($util->isMajorOrMinor('1.1.0'));
        $this->assertTrue($util->isMajorOrMinor('10'));
        $this->assertTrue($util->isMajorOrMinor('10.1'));
        $this->assertFalse($util->isMajorOrMinor('1.1.0.1'));
        $this->assertFalse($util->isMajorOrMinor('1.1.0-1'));
        $this->assertFalse($util->isMajorOrMinor('1.1.1'));
        $this->assertFalse($util->isMajorOrMinor('1.1.1-beta'));
    }

    public function testMajorVersion()
    {
        $util = new VersionUtil();
        $this->assertEquals(1, $util->majorVersion('1.0.0'));
        $this->assertEquals(10, $util->majorVersion('10.1'));
        $this->assertEquals(2, $util->majorVersion('2.x'));
        $this->assertEquals(2, $util->majorVersion('2.1.1'));
        $this->assertEquals(2, $util->majorVersion('2.10.10'));
        $this->assertEquals(3, $util->majorVersion('3.1.0-beta'));
    }

    public function testIsPreRelease()
    {
        $util = new VersionUtil();
        $this->assertEquals(1, $util->majorVersion('1.0.0'));
        $this->assertEquals(10, $util->majorVersion('10.1'));
        $this->assertEquals(2, $util->majorVersion('2.x'));
        $this->assertEquals(2, $util->majorVersion('2.1.1'));
        $this->assertEquals(2, $util->majorVersion('2.10.10'));
        $this->assertEquals(3, $util->majorVersion('3.1.0-beta'));
    }
}
