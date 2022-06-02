<?php

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Tests\Container;

class ApiTest extends TestCase
{
    public function testCompareDomains() {
        $container = Container::instance();
        /** @var Api $api */
        $api = $container->get(Api::class);
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
        \usort($arr, [$api, 'compareDomains']);
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
