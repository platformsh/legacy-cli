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
        $this->assertEquals('9baab201020ed81b3ef1ed47ca5fba95d1aaee78', $treeId);
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', ['clone' => true]);
        $this->assertEquals('ee4348c74e5ccbda3c6b16cc621a7fc05dc61ede', $treeId);
    }
}
