<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Local\LocalBuild;

class LocalBuildTest extends \PHPUnit_Framework_TestCase
{

    const TOOLSTACK_NAMESPACE = 'CommerceGuys\\Platform\\Cli\\Local\\Toolstack\\';

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
        $this->assertCount(2, $applications, 'Detect multiple apps');
    }

    public function testToolstackDetectionNone()
    {
        $fakeAppRoot = 'tests/data/apps';

        $builder = new LocalBuild();
        $this->assertFalse($builder->getToolstack($fakeAppRoot));
    }

    public function testGetAppConfig()
    {
        $fakeAppRoot = 'tests/data/apps';

        $builder = new LocalBuild();
        $config = $builder->getAppConfig($fakeAppRoot);
        $this->assertEquals(array('name' => 'data'), $config);
    }

}
