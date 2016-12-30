<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTreeId()
    {
        $treeId = (new LocalBuild())->getTreeId('tests/data/apps/composer');
        $this->assertEquals('944cb5782066b6bd501677a35a6399b6b7a7c573', $treeId);
    }
}
