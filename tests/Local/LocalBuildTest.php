<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{

    const TOOLSTACK_NAMESPACE = 'Platformsh\\Cli\\Local\\Toolstack\\';

    public function testToolstackDetectionDrupal()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Drupal';
        $appRoot = 'tests/data/apps/drupal';
        $appConfig = array('toolstack' => 'php:drupal');

        $builder = new LocalBuild();

        $toolstackWithConfig = $builder->getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Drupal app from config');

        $toolstackNoConfig = $builder->getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Drupal app from makefile-based file structure');
    }

    public function testToolstackDetectionSymfony()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/symfony';
        $appConfig = array('toolstack' => 'php:symfony');

        $builder = new LocalBuild();

        $toolstackWithConfig = $builder->getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Symfony app from config');

        $toolstackNoConfig = $builder->getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Symfony app from file structure');
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
        $fakeAppRoot = 'tests/data/apps';

        $builder = new LocalBuild();
        $this->assertFalse($builder->getToolstack($fakeAppRoot));
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
