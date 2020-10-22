<?php

namespace Platformsh\Cli\Tests\Local;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTreeId()
    {
        $treeId = (new LocalBuild())->getTreeId('tests/data/apps/composer');
        $this->assertEquals('2cf827fabe61e262376b0356b3aaccf7930eae4c', $treeId);
    }
}
