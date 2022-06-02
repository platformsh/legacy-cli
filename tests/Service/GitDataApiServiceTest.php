<?php

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Tests\Container;

class GitDataApiServiceTest extends TestCase
{
    /** @var GitDataApi */
    private $service;

    public function setUp()
    {
        $container = Container::instance();
        $this->service = $container->get(GitDataApi::class);
    }

    /**
     * Test GitDataApi::parseParents().
     */
    public function testParseParents()
    {
        $reflection = new \ReflectionClass($this->service);
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
            $actual[$spec] = $method->invoke($this->service, $spec);
        }

        $this->assertEquals($expected, $actual);
    }
}
