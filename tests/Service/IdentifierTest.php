<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Identifier;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class IdentifierTest extends TestCase
{
    private function config(): Config
    {
        $configFile = dirname(__DIR__) . '/data/mock-cli-config.yaml';
        return (new Config([], $configFile))->withOverrides(['detection.cluster_header' => null, 'detection.site_domains' => ['example.site']]);
    }

    public function testIdentify(): void
    {
        $identifier = new Identifier($this->config());

        $url = 'https://master-4jkbdba6zde2i.eu-2.example.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://master-4jkbdba6zde2i--foo.eu-2.example.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => 'foo',
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://www---master-4jkbdba6zde2i.eu-2.example.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://eu-2.example.com/projects/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => 'eu-2.example.com',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://eu-2.example.com/projects/4jkbdba6zde2i/environments/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => 'eu-2.example.com',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.example.com/foo/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.example.com/foo/4jkbdba6zde2i/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));
    }

    public function testIdentifyWithEnvironmentIdOf0(): void
    {
        $identifier = new Identifier($this->config());

        $url = 'https://eu-2.example.com/projects/4jkbdba6zde2i/environments/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => 'eu-2.example.com',
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.example.com/foo/4jkbdba6zde2i/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $identifier->identify($url));
    }

    public function testIdentifyWithHyphenPaths(): void
    {
        $identifier = new Identifier($this->config());

        $url = 'https://console.example.com/foo/4jkbdba6zde2i/abc/xyz';
        $expected = ['projectId' => '4jkbdba6zde2i', 'environmentId' => 'abc', 'host' => null, 'appId' => null];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.example.com/foo/4jkbdba6zde2i/-/xyz';
        $expected = ['projectId' => '4jkbdba6zde2i', 'environmentId' => null, 'host' => null, 'appId' => null];
        $this->assertEquals($expected, $identifier->identify($url));

        $url = 'https://console.example.com/foo/-/xyz';
        $this->expectException(InvalidArgumentException::class);
        $identifier->identify($url);
    }
}
