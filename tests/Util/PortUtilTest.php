<?php

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\PortUtil;

class PortUtilTest extends TestCase
{
    public function testGetPortDoesNotReturnPortInUse(): void
    {
        $util = new PortUtil();
        $port = $util->getPort();
        $this->assertFalse($util->isPortInUse($port));

        // Find a listening local port, try getPort() on the port number and
        // test that a new number is returned.
        exec('lsof -sTCP:LISTEN -i@127.0.0.1 -P -n', $output, $returnVar);
        if ($returnVar === 0 && preg_match('/127\.0\.0\.1:([0-9]+)/', end($output), $matches)) {
            $openPort = $matches[1];
            $this->assertNotEquals($util->getPort($openPort), $openPort);
        }
        else {
            $this->markTestIncomplete('Failed to find open port');
        }
    }

    public function testGetPortDoesNotReturnUnsafePort(): void
    {
        $util = new PortUtil();
        $this->assertNotEquals(2049, $util->getPort(2049));
    }

    public function testGetPortReturnsValidPort(): void
    {
        $util = new PortUtil();
        $port = $util->getPort(rand(10000, 50000));
        $this->assertTrue($util->validatePort($port));

        $this->expectException('Exception');

        $this->expectExceptionMessage('Failed to find');
        $util->getPort(70000);
    }

    public function testValidatePort(): void
    {
        $util = new PortUtil();
        $this->assertFalse($util->validatePort(22));
        $this->assertFalse($util->validatePort(0));
        $this->assertFalse($util->validatePort(1000));
        $this->assertFalse($util->validatePort(70000));
        $this->assertFalse($util->validatePort(-1));
        $this->assertFalse($util->validatePort('banana'));
        $this->assertTrue($util->validatePort(3000));
        $this->assertTrue($util->validatePort('3000'));
    }
}
