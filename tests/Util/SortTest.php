<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\Sort;

class SortTest extends TestCase
{
    public function testSortObjects(): void
    {
        $objects = [
            1 => (object) ['foo' => 'a', 'bar' => '1', 'num' => 5],
            2 => (object) ['foo' => 'd', 'bar' => '10', 'num' => 10],
            3 => (object) ['foo' => 'c', 'bar' => '2', 'num' => 50],
            4 => (object) ['foo' => 'e', 'bar' => '-1', 'num' => 0],
        ];
        $cases = [
            ['foo', false, [
                1 => (object) ['foo' => 'a', 'bar' => '1', 'num' => 5],
                3 => (object) ['foo' => 'c', 'bar' => '2', 'num' => 50],
                2 => (object) ['foo' => 'd', 'bar' => '10', 'num' => 10],
                4 => (object) ['foo' => 'e', 'bar' => '-1', 'num' => 0],
            ]],
            ['foo', true, [
                4 => (object) ['foo' => 'e', 'bar' => '-1', 'num' => 0],
                2 => (object) ['foo' => 'd', 'bar' => '10', 'num' => 10],
                3 => (object) ['foo' => 'c', 'bar' => '2', 'num' => 50],
                1 => (object) ['foo' => 'a', 'bar' => '1', 'num' => 5],
            ]],
            ['bar', false, [
                4 => (object) ['foo' => 'e', 'bar' => '-1', 'num' => 0],
                1 => (object) ['foo' => 'a', 'bar' => '1', 'num' => 5],
                3 => (object) ['foo' => 'c', 'bar' => '2', 'num' => 50],
                2 => (object) ['foo' => 'd', 'bar' => '10', 'num' => 10],
            ]],
            ['num', false, [
                4 => (object) ['foo' => 'e', 'bar' => '-1', 'num' => 0],
                1 => (object) ['foo' => 'a', 'bar' => '1', 'num' => 5],
                2 => (object) ['foo' => 'd', 'bar' => '10', 'num' => 10],
                3 => (object) ['foo' => 'c', 'bar' => '2', 'num' => 50],
            ]],
        ];
        foreach ($cases as $i => $case) {
            [$property, $reverse, $expected] = $case;
            $o = $objects;
            Sort::sortObjects($o, $property, $reverse);
            $this->assertEquals($expected, $o, (string) $i);
        }
    }

    public function testCompareDomains(): void
    {
        $arr = [
            'region-1.fxample.com',
            'region-4.example.com',
            'region-1.example.com',
            'region-3.example.com',
            'a',
            'example.com',
            'Region-2.example.com',
            'region-10.example.com',
            'region-2.fxample.com',
            'region.example.com',
        ];
        \usort($arr, Sort::compareDomains(...));
        $this->assertEquals([
            'a',
            'example.com',
            'region.example.com',
            'region-1.example.com',
            'Region-2.example.com',
            'region-3.example.com',
            'region-4.example.com',
            'region-10.example.com',
            'region-1.fxample.com',
            'region-2.fxample.com',
        ], $arr);
    }
}
