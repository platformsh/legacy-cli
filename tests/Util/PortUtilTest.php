<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\PortUtil;

class PortUtilTest extends \PHPUnit_Framework_TestCase
{
    public function testGetPortDoesNotReturnPortInUse()
    {
        $util = new PortUtil();
        $port = $util->getPort();
        $this->assertFalse($util->isPortInUse($port));

        // Scan for an open local port, try getPort() on the port number and
        // test that a new number is returned.
        for ($start = 25, $end = 74; $end <= 1024; $start += 50, $end += 50) {
            exec(sprintf('nc -z 127.0.0.1 %d-%d 2>&1', $start, $end), $output, $returnVar);
            if ($returnVar === 0 && preg_match('/port ([0-9]+)/', end($output), $matches)) {
                $openPort = $matches[1];
                $this->assertNotEquals($util->getPort($openPort), $openPort);
                break;
            }
        }
        if (!isset($openPort)) {
            $this->markTestIncomplete('Failed to find open port');
        }
    }

    public function testGetPortDoesNotReturnUnsafePort()
    {
        $util = new PortUtil();
        $this->assertNotEquals($util->getPort(22), 22);
    }

    public function testGetPortReturnsValidPort()
    {
        $util = new PortUtil();
        $port = $util->getPort(rand(10000, 50000));
        $this->assertTrue($util->validatePort($port));

        $this->setExpectedException('Exception', 'Failed to find a port');
        $util->getPort(70000);
    }

    public function testValidatePort()
    {
        $util = new PortUtil();
        $this->assertFalse($util->validatePort(22));
        $this->assertFalse($util->validatePort(70000));
        $this->assertFalse($util->validatePort(-1));
        $this->assertFalse($util->validatePort('banana'));
        $this->assertTrue($util->validatePort(3000));
        $this->assertTrue($util->validatePort('3000'));
    }
}
