<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\VersionUtil;

class VersionUtilTest extends TestCase
{
    public function testNextVersions(): void
    {
        $util = new VersionUtil();
        $this->assertEquals(['1.0.1', '1.1.0', '2.0.0'], $util->nextVersions('1.0.0'));
        $this->assertEquals(['1.0.2', '1.1.0', '2.0.0'], $util->nextVersions('1.0.1'));
        $this->assertEquals(['2.0.3-beta', '2.1.0-beta', '3.0.0-beta'], $util->nextVersions('2.0.2-beta'));
    }

    public function testMajorVersion(): void
    {
        $util = new VersionUtil();
        $this->assertEquals(1, $util->majorVersion('1.0.0'));
        $this->assertEquals(10, $util->majorVersion('10.1'));
        $this->assertEquals(2, $util->majorVersion('2.x'));
        $this->assertEquals(2, $util->majorVersion('2.1.1'));
        $this->assertEquals(2, $util->majorVersion('2.10.10'));
        $this->assertEquals(3, $util->majorVersion('3.1.0-beta'));
    }

    public function testIsPreRelease(): void
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
