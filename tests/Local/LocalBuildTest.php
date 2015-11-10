<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTreeId()
    {
        $builder = new LocalBuild();
        $treeId = $builder->getTreeId('tests/data/apps/composer');
        $this->assertEquals('4e071b6e03bab926fe4a5f4d3bbdeb00bc8051a1', $treeId);
    }
}
