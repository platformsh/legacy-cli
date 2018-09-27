<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Api;

class ApiServiceTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Test Api::parseParents().
     */
    public function testParseParents()
    {
        $api = new Api();
        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('parseParents');
        $method->setAccessible(true);

        $expected = [
            'foo1^' => [1],
            'bar^^2' => [1, 2],
            'baz2^3^^' => [3, 1, 1],
            'bal~~^2^1~2' => [1, 1, 2, 1, 1, 1],
        ];

        $actual = [];
        foreach (array_keys($expected) as $spec) {
            $actual[$spec] = $method->invoke($api, $spec);
        }

        $this->assertEquals($expected, $actual);
    }
}
