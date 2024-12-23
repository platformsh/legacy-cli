<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;

class ConfigTest extends TestCase
{
    private string $configFile;

    public function setUp(): void
    {
        $this->configFile = __DIR__ . '/data/mock-cli-config.yaml';
    }

    /**
     * Test loading config from file.
     */
    public function testLoadMainConfig(): void
    {
        $config = new Config([], $this->configFile);
        $this->assertTrue($config->has('application.name'));
        $this->assertFalse($config->has('nonexistent'));
        $this->assertEquals('Mock CLI', $config->getStr('application.name'));
        $this->assertEquals(123, $config->getWithDefault('nonexistent', 123));
    }

    public function testGetHomeDirectory(): void
    {
        $homeDir = (new Config(['HOME' => '.'], $this->configFile))->getHomeDirectory();
        $this->assertNotEmpty($homeDir, 'Home directory returned');
        $this->assertNotEquals('.', $homeDir, 'Home directory not relative');

        $homeDir = (new Config(['MOCK_CLI_HOME' => __DIR__ . '/data', 'HOME' => __DIR__], $this->configFile))->getHomeDirectory();
        $this->assertEquals(__DIR__ . '/data', $homeDir, 'Home directory overridden');

        $homeDir = (new Config(['MOCK_CLI_HOME' => '', 'HOME' => __DIR__], $this->configFile))->getHomeDirectory();
        $this->assertEquals(__DIR__, $homeDir, 'Empty value treated as nonexistent');
    }

    /**
     * Test that selected environment variables can override initial config.
     */
    public function testEnvironmentOverrides(): void
    {
        new Config([], $this->configFile);
        putenv('MOCK_CLI_DISABLE_CACHE=0');
        $config = new Config([
            'MOCK_CLI_APPLICATION_NAME' => 'Overridden application name',
            'MOCK_CLI_DEBUG' => '1',
        ], $this->configFile);
        $this->assertFalse((bool) $config->get('api.disable_cache'));
        $this->assertTrue((bool) $config->get('api.debug'));
        $this->assertEquals('Overridden application name', $config->get('application.name'));
    }

    /**
     * Test that selected user config can override initial config.
     */
    public function testUserConfigOverrides(): void
    {
        $config = new Config([], $this->configFile);
        $this->assertFalse($config->has('experimental.test'));
        $home = getenv('HOME');
        putenv('HOME=' . __DIR__ . '/data');
        $config = new Config([], $this->configFile);
        putenv('HOME=' . $home);
        $this->assertTrue($config->has('experimental.test'));
        $this->assertTrue($config->get('experimental.test'));
    }

    /**
     * Test misc. dynamic defaults.
     */
    public function testDynamicDefaults(): void
    {
        $config = new Config([], $this->configFile);
        $this->assertEquals('mock-cli', $config->get('application.slug'));
        $this->assertEquals('mock-cli-tmp', $config->get('application.tmp_sub_dir'));
        $this->assertEquals('mock-cli', $config->get('api.oauth2_client_id'));
        $this->assertEquals('console.example.com', $config->get('detection.console_domain'));
        $this->assertEquals('.mock/applications.yaml', $config->get('service.applications_config_file'));
        $this->assertEquals('X-Mock-Cluster', $config->get('detection.cluster_header'));
    }

    /**
     * Test dynamic defaults for URLs.
     */
    public function testDynamicUrlDefaults(): void
    {
        $config = new Config(['MOCK_CLI_AUTH_URL' => 'https://auth.example.com'], $this->configFile);
        $this->assertEquals('https://auth.example.com/oauth2/token', $config->get('api.oauth2_token_url'));
        $this->assertEquals('https://auth.example.com/oauth2/authorize', $config->get('api.oauth2_auth_url'));
        $this->assertEquals('https://auth.example.com/oauth2/revoke', $config->get('api.oauth2_revoke_url'));
    }

    /**
     * Test dynamic defaults for local paths.
     */
    public function testLocalPathDefaults(): void
    {
        $config = new Config([], $this->configFile);
        $this->assertEquals('.mock/local', $config->get('local.local_dir'));
        $this->assertEquals('.mock/local/project.yaml', $config->get('local.project_config'));
        $this->assertEquals('.mock/local/builds', $config->get('local.build_dir'));
        $this->assertEquals('.mock/local/build-archives', $config->get('local.archive_dir'));
        $this->assertEquals('.mock/local/deps', $config->get('local.dependencies_dir'));
        $this->assertEquals('.mock/local/shared', $config->get('local.shared_dir'));
        putenv('MOCK_CLI_LOCAL_SHARED_DIR=/tmp/shared');
        $config = new Config([], $this->configFile);
        $this->assertEquals('/tmp/shared', $config->get('local.shared_dir'));
    }

    /**
     * Test the default for application.writable_user_dir
     */
    public function testGetWritableUserDir(): void
    {
        $config = new Config([], $this->configFile);
        $this->assertEquals('mock-cli-user-config', $config->get('application.user_config_dir'));
        $this->assertEquals(null, $config->get('application.writable_user_dir'));
        $home = $config->getHomeDirectory();
        $this->assertEquals($home . DIRECTORY_SEPARATOR . 'mock-cli-user-config', $config->getWritableUserDir());
    }
}
