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
        $this->assertEquals(123, $config->getWithDefault('nonexistent', 123));
    }

    public function testGetHomeDirectory()
    {
        $homeDir = (new Config(['HOME' => '.']))->getHomeDirectory();
        $this->assertNotEmpty($homeDir, 'Home directory returned');
        $this->assertNotEquals('.', $homeDir, 'Home directory not relative');

        $homeDir = (new Config(['PLATFORMSH_CLI_HOME' => __DIR__ . '/data', 'HOME' => __DIR__]))->getHomeDirectory();
        $this->assertEquals(__DIR__ . '/data', $homeDir, 'Home directory overridden');

        $homeDir = (new Config(['PLATFORMSH_CLI_HOME' => '', 'HOME' => __DIR__]))->getHomeDirectory();
        $this->assertEquals(__DIR__, $homeDir, 'Empty value treated as nonexistent');
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

    public function tearDown()
    {
        new Config(null, null, true);
    }
}
