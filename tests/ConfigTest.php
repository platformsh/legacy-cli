<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Config;

class ConfigTest extends \PHPUnit_Framework_TestCase
{
    private $defaultsFile;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->defaultsFile = __DIR__ . '/data/mock-cli-config.yaml';
    }

    /**
     * Test loading config from file.
     */
    public function testLoadMainConfig()
    {
        $config = new Config([], $this->defaultsFile);
        $this->assertTrue($config->has('application.name'));
        $this->assertFalse($config->has('nonexistent'));
        $this->assertEquals('Mock CLI', $config->get('application.name'));
        $this->assertEquals(123, $config->getWithDefault('nonexistent', 123));
    }

    public function testGetHomeDirectory()
    {
        $homeDir = (new Config(['HOME' => '.'], $this->defaultsFile))->getHomeDirectory();
        $this->assertNotEmpty($homeDir, 'Home directory returned');
        $this->assertNotEquals('.', $homeDir, 'Home directory not relative');

        $homeDir = (new Config(['MOCK_CLI_HOME' => __DIR__ . '/data', 'HOME' => __DIR__],  $this->defaultsFile))->getHomeDirectory();
        $this->assertEquals(__DIR__ . '/data', $homeDir, 'Home directory overridden');

        $homeDir = (new Config(['MOCK_CLI_HOME' => '', 'HOME' => __DIR__],  $this->defaultsFile))->getHomeDirectory();
        $this->assertEquals(__DIR__, $homeDir, 'Empty value treated as nonexistent');
    }

    /**
     * Test that selected environment variables can override initial config.
     */
    public function testEnvironmentOverrides()
    {
        $config = new Config([], $this->defaultsFile);
        $this->assertFalse($config->has('api.debug'));
        putenv('MOCK_CLI_DISABLE_CACHE=1');
        $config = new Config([
            'MOCK_CLI_APPLICATION_NAME' => 'Overridden application name',
            'MOCK_CLI_DEBUG' => 1,
        ], $this->defaultsFile);
        $this->assertNotEmpty($config->get('api.disable_cache'));
        $this->assertNotEmpty($config->get('api.debug'));
        $this->assertEquals('Overridden application name', $config->get('application.name'));
    }

    /**
     * Test that selected user config can override initial config.
     */
    public function testUserConfigOverrides()
    {
        $config = new Config([], $this->defaultsFile);
        $this->assertFalse($config->has('experimental.test'));
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new Config([], $this->defaultsFile);
        putenv('HOME=' . $home);
        $this->assertTrue($config->has('experimental.test'));
        $this->assertTrue($config->get('experimental.test'));
        $this->assertNotEquals('Attempted override', $config->get('application.name'));
    }
}
