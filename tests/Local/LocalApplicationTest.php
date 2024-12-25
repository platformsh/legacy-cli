<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\BuildFlavor\NoBuildFlavor;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Config;

class LocalApplicationTest extends TestCase
{
    private Config $config;

    public function setUp(): void
    {
        $this->config = (new Config())->withOverrides([
            'service.app_config_file' => '_platform.app.yaml',
            'service.applications_config_file' =>  '_platform/applications.yaml',
        ]);
    }

    public function testBuildFlavorDetectionDrupal(): void
    {
        $appRoot = 'tests/data/apps/drupal/project';

        $app = new LocalApplication($appRoot, $this->config);

        $this->assertInstanceOf(Drupal::class, $app->getBuildFlavor());
    }

    public function testBuildFlavorDetectionSymfony(): void
    {
        $appRoot = 'tests/data/apps/symfony';

        $app = new LocalApplication($appRoot, $this->config);

        $this->assertInstanceOf(Symfony::class, $app->getBuildFlavor());
    }

    /**
     * Test the special case of HHVM buildFlavor types being the same as PHP.
     */
    public function testBuildFlavorAliasHhvm(): void
    {
        $appRoot = 'tests/data/apps/vanilla';

        $app = new LocalApplication($appRoot, $this->config, null, new AppConfig([
            'type' => 'hhvm:3.7',
            'build' => ['flavor' => 'symfony'],
        ]));
        $buildFlavor = $app->getBuildFlavor();

        $this->assertInstanceOf(Symfony::class, $buildFlavor);
    }

    public function testBuildFlavorDetectionNone(): void
    {
        $fakeAppRoot = 'tests/data/apps/none';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $this->assertInstanceOf(NoBuildFlavor::class, $app->getBuildFlavor(), 'Config does not indicate a specific build flavor');
    }

    public function testGetAppConfig(): void
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/simple';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'simple'], $config);
        $this->assertEquals('simple', $app->getId());
    }

    public function testGetAppConfigNested(): void
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest/nested';

        $app = new LocalApplication($fakeAppRoot, $this->config);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'nested1'], $config);
        $this->assertEquals('nested1', $app->getName());
        $this->assertEquals('nested1', $app->getId());
    }

    public function testGetSharedFileMounts(): void
    {
        $appRoot = 'tests/data/apps/drupal/project';
        $app = new LocalApplication($appRoot, $this->config);
        $this->assertEquals([
            'public/sites/default/files' => 'files',
            'tmp' => 'tmp',
            'private' => 'private',
            'drush-backups' => 'drush-backups',
        ], $app->getSharedFileMounts());

    }
}
