<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTreeId()
    {
        $builder = new LocalBuild();
        $treeId = $builder->getTreeId('tests/data/apps/composer');
        $this->assertEquals('205056a25e6cef7cc71cf3ff2c1dd0eedfcc15af', $treeId);
    }
}
