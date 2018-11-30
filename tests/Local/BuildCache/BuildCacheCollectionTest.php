<?php

namespace Platformsh\Cli\Tests\Local\BuildCache;

use Platformsh\Cli\Local\BuildCache\BuildCacheCollection;

class BuildCacheCollectionTest extends \PHPUnit_Framework_TestCase
{
    public function testPreventNestedDirectories()
    {
        $this->setExpectedException('InvalidArgumentException', 'Cache directories cannot be nested (foo/bar is inside foo)');
        BuildCacheCollection::fromAppConfig([
            'caches' => [
                'foo/bar' => [
                    'watch' => 'watch1',
                ],
                'key1' => [
                    'directory' => 'foo',
                    'watch' => ['watch2', 'watch3'],
                ],
                'key2' => [
                    'directory' => 'bar',
                    'watch' => 'watch4',
                ],
            ],
        ]);
    }
}
