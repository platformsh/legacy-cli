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

        $toolstackWithConfig = LocalBuild::getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Drupal app from config');

        $toolstackNoConfig = LocalBuild::getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Drupal app from makefile-based file structure');
    }

    public function testToolstackDetectionSymfony()
    {
        $toolstackClassName = self::TOOLSTACK_NAMESPACE . 'Symfony';
        $appRoot = 'tests/data/apps/symfony';
        $appConfig = array('toolstack' => 'php:symfony');

        $toolstackWithConfig = LocalBuild::getToolstack($appRoot, $appConfig);
        $this->assertInstanceOf($toolstackClassName, $toolstackWithConfig, 'Detect Symfony app from config');

        $toolstackNoConfig = LocalBuild::getToolstack($appRoot);
        $this->assertInstanceOf($toolstackClassName, $toolstackNoConfig, 'Detect Symfony app from file structure');
    }

}
