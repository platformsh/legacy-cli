<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\CliConfig;

class CliConfigTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test loading config from file.
     */
    public function testLoadMainConfig()
    {
        $config = new CliConfig([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertTrue($config->has('application.name'));
        $this->assertFalse($config->has('nonexistent'));
        $this->assertEquals($config->get('application.name'), 'Mock CLI');
    }

    /**
     * Test that selected environment variables can override initial config.
     */
    public function testEnvironmentOverrides()
    {
        $config = new CliConfig([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertFalse($config->has('api.debug'));
        $config = new CliConfig([
            'MOCK_CLI_APPLICATION_NAME' => 'Attempted override',
            'MOCK_CLI_DEBUG' => 1,
        ], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertTrue($config->has('api.debug'));
        $this->assertNotEmpty($config->get('api.debug'));
        $this->assertNotEquals($config->get('application.name'), 'Attempted override');
    }

    /**
     * Test that selected user config can override initial config.
     */
    public function testUserConfigOverrides()
    {
        $config = new CliConfig([], __DIR__ . '/data/mock-cli-config.yaml', true);
        $this->assertFalse($config->has('experimental.test'));
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new CliConfig([], __DIR__ . '/data/mock-cli-config.yaml', true);
        putenv('HOME=' . $home);
        $this->assertTrue($config->has('experimental.test'));
        $this->assertTrue($config->get('experimental.test'));
        $this->assertNotEquals($config->get('application.name'), 'Attempted override');
    }

    public function tearDown()
    {
        new CliConfig(null, null, true);
    }
}
