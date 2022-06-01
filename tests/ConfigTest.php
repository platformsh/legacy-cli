<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;

class ConfigTest extends TestCase
{
    /**
     * Test loading config from file.
     */
    public function testLoadMainConfig()
    {
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml');
        $this->assertTrue($config->has('application.name'));
        $this->assertFalse($config->has('nonexistent'));
        $this->assertEquals('Mock CLI', $config->get('application.name'));
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
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml');
        $this->assertFalse($config->has('api.debug'));
        putenv('MOCK_CLI_DISABLE_CACHE=1');
        $config = new Config([
            'MOCK_CLI_APPLICATION_NAME' => 'Overridden application name',
            'MOCK_CLI_DEBUG' => 1,
        ], __DIR__ . '/data/mock-cli-config.yaml');
        $this->assertNotEmpty($config->get('api.disable_cache'));
        $this->assertNotEmpty($config->get('api.debug'));
        $this->assertEquals('Overridden application name', $config->get('application.name'));
    }

    /**
     * Test that selected user config can override initial config.
     */
    public function testUserConfigOverrides()
    {
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml');
        $this->assertFalse($config->has('experimental.test'));
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml');
        putenv('HOME=' . $home);
        $this->assertTrue($config->has('experimental.test'));
        $this->assertTrue($config->get('experimental.test'));
        $this->assertNotEquals('Attempted override', $config->get('application.name'));
    }
}
