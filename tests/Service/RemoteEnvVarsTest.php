<?php

namespace Platformsh\Cli\Tests\Service;

use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Tests\Container;

class RemoteEnvVarsTest extends \PHPUnit_Framework_TestCase {
    /** @var RemoteEnvVars */
    private $service;

    public function setUp() {
        $container = Container::instance();
        $this->service = $container->get('remote_env_vars');
    }

    public function testExtractResult()
    {
        $method = new \ReflectionMethod($this->service, 'extractResult');
        $method->setAccessible(true);
        $cases = [
            // Standard.
            ['_BEGIN_', '_END_', 'PAYLOAD', '', ''],
            // With messages before and after.
            ['-BEGIN-', '-END-', '-PAYLOAD-', '-before-', '-after-'],
            // With extra whitespace.
            ["\n--BEGIN--\n\t", "\n\t-END-\n\t\n", "\n\tPAYLOAD\t\n", "\$ # before\n", "\n\$ # after\n"],
        ];
        foreach ($cases as $case) {
            list($begin, $end, $payload, $before, $after) = $case;
            $this->assertEquals($payload, $method->invoke($this->service, $before . $begin . $payload . $end . $after, $begin, $end));
        }
    }
}
