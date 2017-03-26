<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    public function testGetTreeId()
    {
        $treeId = (new LocalBuild())->getTreeId('tests/data/apps/composer');
        $this->assertEquals('07508d5434997083612165e414b62cc6ee5d2b30', $treeId);
    }
}
