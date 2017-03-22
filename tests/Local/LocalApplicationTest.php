<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\BuildFlavor\NoBuildFlavor;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Platformsh\Cli\Local\LocalApplication;

class LocalApplicationTest extends \PHPUnit_Framework_TestCase
{

    public function testBuildFlavorDetectionDrupal()
    {
        $appRoot = 'tests/data/apps/drupal/project';

        $app = new LocalApplication($appRoot);

        $this->assertInstanceOf(Drupal::class, $app->getBuildFlavor());
    }

    public function testBuildFlavorDetectionSymfony()
    {
        $appRoot = 'tests/data/apps/symfony';

        $app = new LocalApplication($appRoot);

        $this->assertInstanceOf(Symfony::class, $app->getBuildFlavor());
    }

    /**
     * Test the special case of HHVM buildFlavor types being the same as PHP.
     */
    public function testBuildFlavorAliasHhvm()
    {
        $appRoot = 'tests/data/apps/vanilla';

        $app = new LocalApplication($appRoot);
        $app->setConfig(['type' => 'hhvm:3.7', 'build' => ['flavor' => 'symfony']]);;
        $buildFlavor = $app->getBuildFlavor();

        $this->assertInstanceOf(Symfony::class, $buildFlavor);
    }

    public function testBuildFlavorDetectionMultiple()
    {
        $fakeRepositoryRoot = 'tests/data/repositories/multiple';

        $applications = LocalApplication::getApplications($fakeRepositoryRoot);
        $this->assertCount(6, $applications, 'Detect multiple apps');
    }

    public function testBuildFlavorDetectionNone()
    {
        $fakeAppRoot = 'tests/data/apps/none';

        $app = new LocalApplication($fakeAppRoot);
        $this->assertInstanceOf(NoBuildFlavor::class, $app->getBuildFlavor(), 'Config does not indicate a specific build flavor');
    }

    public function testGetAppConfig()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/simple';

        $app = new LocalApplication($fakeAppRoot);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'simple'], $config);
        $this->assertEquals('simple', $app->getId());
    }

    public function testGetAppConfigNested()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest/nested';

        $app = new LocalApplication($fakeAppRoot);
        $config = $app->getConfig();
        $this->assertEquals(['name' => 'nested1'], $config);
        $this->assertEquals('nested1', $app->getName());
        $this->assertEquals('nested1', $app->getId());
    }

    public function testGetSharedFileMounts()
    {
        $appRoot = 'tests/data/apps/drupal/project';
        $app = new LocalApplication($appRoot);
        $this->assertEquals([
            'public/sites/default/files' => 'files',
            'tmp' => 'tmp',
            'private' => 'private',
            'drush-backups' => 'drush-backups',
        ], $app->getSharedFileMounts());

    }
}
