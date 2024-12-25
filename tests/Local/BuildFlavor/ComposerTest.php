<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use PHPUnit\Framework\Attributes\Group;

#[Group('slow')]
class ComposerTest extends BuildFlavorTestBase
{
    public function testBuildComposer(): void
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer');
        $webRoot = $projectRoot . '/' . self::$config->getStr('local.web_root');
        $this->assertFileExists($webRoot . '/vendor/psr/log/README.md');
    }

    public function testBuildComposerCustomPhp(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/composer-php56');
    }

    public function testBuildComposerHhvm(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/hhvm37');
    }

    public function testBuildComposerMounts(): void
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer-mounts', [
            'copy' => true,
            'abslinks' => true,
        ]);
        $webRoot = $projectRoot . '/' . self::$config->getStr('local.web_root');
        $shared = $projectRoot . '/' . self::$config->getStr('local.shared_dir');
        $buildDir = $projectRoot . '/' . self::$config->getStr('local.build_dir') . '/default';

        $this->assertFileExists($webRoot . '/js');
        $this->assertFileExists($webRoot . '/css');
        $this->assertFileExists($buildDir . '/cache');
        $this->assertEquals($shared . '/assets/js', readlink($webRoot . '/js'));
        $this->assertEquals($shared . '/assets/css', readlink($webRoot . '/css'));
        $this->assertEquals($shared . '/cache', readlink($buildDir . '/cache'));
    }

    /**
     * Test the case where a user has specified "symfony" as the build flavor,
     * for an application which does not contain a composer.json file. The build
     * may not do much, but at least it should not throw an exception.
     */
    public function testBuildFakeSymfony(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/fake-symfony');
    }

    /**
     * Test the deprecated config file format still works.
     */
    public function testBuildDeprecatedConfig(): void
    {
        $this->assertBuildSucceeds('tests/data/apps/deprecated-config');
    }
}
