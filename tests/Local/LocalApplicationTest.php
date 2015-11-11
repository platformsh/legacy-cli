<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalApplication;

class LocalApplicationTest extends \PHPUnit_Framework_TestCase
{

    const TOOLSTACK_NAMESPACE = 'Platformsh\\Cli\\Local\\Toolstack\\';

    public function testToolstackDetectionDrupal()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Drupal';
        $appRoot = 'tests/data/apps/drupal/project';

        $app = new LocalApplication($appRoot);

        $toolstackWithConfig = $app->getToolstack();
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Drupal app from config');

        $app->setConfig([]);
        $toolstackNoConfig = $app->getToolstack();
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Drupal app from makefile-based file structure');
    }

    public function testToolstackDetectionSymfony()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/symfony';

        $app = new LocalApplication($appRoot);

        $toolstackWithConfig = $app->getToolstack();
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Symfony app from config');

        $app->setConfig([]);
        $toolstackNoConfig = $app->getToolstack();
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Symfony app from file structure');
    }

    /**
     * Test the special case of HHVM toolstack types being the same as PHP.
     */
    public function testToolstackAliasHhvm()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/vanilla';

        $app = new LocalApplication($appRoot);
        $app->setConfig(['type' => 'hhvm:3.7', 'build' => ['flavor' => 'symfony']]);;
        $toolstack = $app->getToolstack();

        $this->assertInstanceOf($toolstackClassName, $toolstack, 'Detect HHVM Symfony app from config');
    }

    public function testToolstackDetectionMultiple()
    {
        $fakeRepositoryRoot = 'tests/data/repositories/multiple';

        $applications = LocalApplication::getApplications($fakeRepositoryRoot);
        $this->assertCount(6, $applications, 'Detect multiple apps');
    }

    public function testToolstackDetectionNone()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'NoToolstack';
        $fakeAppRoot = 'tests/data/apps';

        $app = new LocalApplication($fakeAppRoot);
        $this->assertInstanceOf($toolstackClassName, $app->getToolstack(), 'File structure does not indicate a specific toolstack');
    }

    public function testGetAppConfig()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/simple';

        $app = new LocalApplication($fakeAppRoot);
        $config = $app->getConfig();
        $this->assertEquals(array('name' => 'simple'), $config);
        $this->assertEquals('simple', $app->getId());
    }

    public function testGetAppConfigNested()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest/nested';

        $app = new LocalApplication($fakeAppRoot);
        $config = $app->getConfig();
        $this->assertEquals(array('name' => 'nested1'), $config);
        $this->assertEquals('nested1', $app->getName());
        $this->assertEquals('nested1', $app->getId());
    }
}
