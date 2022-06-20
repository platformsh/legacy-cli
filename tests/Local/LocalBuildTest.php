<?php

namespace Platformsh\Cli\Tests\Local;

use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{
    /** @var LocalBuild|null */
    private $localBuild;

    public function setUp() {
        $container = Container::instance();
        $container->set('input', new ArrayInput([]));
        /** @var \Platformsh\Cli\Local\LocalBuild localBuild */
        $this->localBuild = $container->get('local.build');
    }

    public function testGetTreeId()
    {
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', []);
        $this->assertEquals('2cf827fabe61e262376b0356b3aaccf7930eae4c', $treeId);
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', ['clone' => true]);
        $this->assertEquals('e24740418e9efa6f4c07ad61c0119119da4aaf1c', $treeId);
    }
}
