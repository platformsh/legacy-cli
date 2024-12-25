<?php

namespace Platformsh\Cli\Tests\Local;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;

class LocalBuildTest extends TestCase
{
    /** @var LocalBuild|null */
    private $localBuild;

    public function setUp(): void
    {
        $container = Container::instance();
        $container->set('input', new ArrayInput([]));
        /** @var LocalBuild localBuild */
        $this->localBuild = $container->get('local.build');
    }

    public function testGetTreeId()
    {
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', []);
        $this->assertEquals('0d9f5dd9a2907d905efc298686bb3c4e2f9a4811', $treeId);
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', ['clone' => true]);
        $this->assertEquals('7f63ba117166a67cf217294e6a2c7b20c96e09f6', $treeId);
    }
}
