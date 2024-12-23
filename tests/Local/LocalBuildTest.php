<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LocalBuildTest extends TestCase
{
    private ?LocalBuild $localBuild;

    public function setUp(): void
    {
        $container = Container::instance();
        $container->set(Config::class, new Config([], __DIR__ . '/../data/mock-cli-config.yaml'));
        $container->set(InputInterface::class, new ArrayInput([]));
        $container->set(OutputInterface::class, new BufferedOutput());
        $this->localBuild = $container->get(LocalBuild::class);
    }

    public function testGetTreeId(): void
    {
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', []);
        $this->assertEquals('0d9f5dd9a2907d905efc298686bb3c4e2f9a4811', $treeId);
        $treeId = $this->localBuild->getTreeId('tests/data/apps/composer', ['clone' => true]);
        $this->assertEquals('7f63ba117166a67cf217294e6a2c7b20c96e09f6', $treeId);
    }
}
