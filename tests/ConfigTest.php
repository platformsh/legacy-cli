<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Config;

class ConfigTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test loading config from file.
     */
    public function testLoadMainConfig()
    {
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertTrue($config->has('application.name'));
        $this->assertFalse($config->has('nonexistent'));
        $this->assertEquals($config->get('application.name'), 'Mock CLI');
    }

    /**
     * Test that selected environment variables can override initial config.
     */
    public function testEnvironmentOverrides()
    {
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertFalse($config->has('api.debug'));
        putenv('MOCK_CLI_DISABLE_CACHE=1');
        $config = new Config([
            'MOCK_CLI_APPLICATION_NAME' => 'Attempted override',
            'MOCK_CLI_DEBUG' => 1,
        ], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertNotEmpty($config->get('api.disable_cache'));
        $this->assertNotEmpty($config->get('api.debug'));
        $this->assertNotEquals($config->get('application.name'), 'Attempted override');
    }

    /**
     * Test that selected user config can override initial config.
     */
    public function testUserConfigOverrides()
    {
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertFalse($config->has('experimental.test'));
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);
        putenv('HOME=' . $home);
        $this->assertTrue($config->has('experimental.test'));
        $this->assertTrue($config->get('experimental.test'));
        $this->assertNotEquals($config->get('application.name'), 'Attempted override');
    }

    /**
     * Test that global config can override initial config.
     */
    public function testGlobalConfigOverrides()
    {
        // Set up so that the current directory does not include global config,
        // and so that user config is available.
        $cwd = getcwd();
        chdir(__DIR__);
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);

        // Assert that global config is not yet loaded.
        $this->assertFalse($config->has('experimental.test_global'));

        // Set up so that the current directory does include global config.
        chdir(__DIR__ . '/data');
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml', true);

        // Assert that global config is loaded.
        $this->assertTrue($config->get('experimental.test_global'));

        // Assert that user config is also loaded, and that it has not been
        // overridden by the global config.
        $this->assertTrue($config->get('experimental.test'));

        // Clean up.
        chdir($cwd);
        putenv('HOME=' . $home);
    }

    public function tearDown()
    {
        new Config(null, null, true);
    }
}
