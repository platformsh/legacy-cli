<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{

    const TOOLSTACK_NAMESPACE = 'Platformsh\\Cli\\Local\\Toolstack\\';

    public function testToolstackDetectionDrupal()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Drupal';
        $appRoot = 'tests/data/apps/drupal/project';

        $builder = new LocalBuild();

        $appConfig = array('type' => 'php', 'build' => array('flavor' => 'drupal'));
        $toolstackWithConfig = $builder->getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Drupal app from config');

        $toolstackNoConfig = $builder->getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Drupal app from makefile-based file structure');
    }

    public function testToolstackDetectionSymfony()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/symfony';

        $builder = new LocalBuild();

        $appConfig = array('type' => 'php', 'build' => array('flavor' => 'symfony'));
        $toolstackWithConfig = $builder->getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Symfony app from config');

        $toolstackNoConfig = $builder->getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Symfony app from file structure');
    }

    /**
     * Test the special case of HHVM toolstack types being the same as PHP.
     */
    public function testToolstackAliasHhvm()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/vanilla';
        $appConfig = array('type' => 'hhvm:3.7', 'build' => array('flavor' => 'symfony'));

        $builder = new LocalBuild();

        $toolstack = $builder->getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstack, 'Detect HHVM Symfony app from config');
    }

    public function testToolstackDetectionMultiple()
    {
        $fakeRepositoryRoot = 'tests/data/repositories/multiple';

        $builder = new LocalBuild();
        $applications = $builder->getApplications($fakeRepositoryRoot);
        $this->assertCount(6, $applications, 'Detect multiple apps');
    }

    public function testToolstackDetectionNone()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'NoToolstack';
        $fakeAppRoot = 'tests/data/apps';

        $builder = new LocalBuild();
        $this->assertInstanceOf($toolstackClassName, $builder->getToolstack($fakeAppRoot), 'File structure does not indicate a specific toolstack');
    }

    public function testGetAppConfig()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/simple';

        $builder = new LocalBuild();
        $config = $builder->getAppConfig($fakeAppRoot);
        $this->assertEquals(array('name' => 'simple'), $config);
    }

    public function testGetAppConfigNested()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest/nested';

        $builder = new LocalBuild();
        $config = $builder->getAppConfig($fakeAppRoot);
        $this->assertEquals(array('name' => 'nested1'), $config);
    }
}
