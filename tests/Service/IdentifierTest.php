<?php

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Exception\InvalidArgumentException;

class IdentifierTest extends TestCase
{
    /** @var Identifier */
    private $identifier;

    public function setUp(): void
    {
        $container = Container::instance();
        $this->identifier = $container->get(Identifier::class);
    }

    public function testIdentify()
    {
        $url = 'https://master-4jkbdba6zde2i.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://master-4jkbdba6zde2i--foo.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => 'foo',
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://www---master-4jkbdba6zde2i.eu-2.platformsh.site';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'master',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i/environments/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => null,
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/bar';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => 'bar',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));
    }

    public function testIdentifyWithEnvironmentIdOf0()
    {
        $url = 'https://eu-2.platform.sh/projects/4jkbdba6zde2i/environments/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => 'eu-2.platform.sh',
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/0';
        $expected = [
            'projectId' => '4jkbdba6zde2i',
            'environmentId' => '0',
            'host' => null,
            'appId' => null,
        ];
        $this->assertEquals($expected, $this->identifier->identify($url));
    }

    public function testIdentifyWithHyphenPaths()
    {
        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/abc/xyz';
        $expected = ['projectId' => '4jkbdba6zde2i', 'environmentId' => 'abc', 'host' => null, 'appId' => null];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://console.platform.sh/foo/4jkbdba6zde2i/-/xyz';
        $expected = ['projectId' => '4jkbdba6zde2i', 'environmentId' => null, 'host' => null, 'appId' => null];
        $this->assertEquals($expected, $this->identifier->identify($url));

        $url = 'https://console.platform.sh/foo/-/xyz';
        $this->expectException(InvalidArgumentException::class);
        $this->identifier->identify($url);
    }
}
