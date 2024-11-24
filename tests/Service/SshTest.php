<?php

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;

class SshTest extends TestCase {
    /** @var Ssh|null */
    private $ssh;

    public function setUp(): void
    {
        $container = Container::instance();
        $container->set('input', new ArrayInput([]));
        $container->set('config', (new Config())->withOverrides([
            'ssh.domain_wildcards' => ['*.ssh.example.com'],
        ]));
        $this->ssh = $container->get('ssh');
    }

    public function testGetHost()
    {
        $method = new \ReflectionMethod($this->ssh, 'getHost');
        $method->setAccessible(true);
        $this->assertEquals('xyz.ssh.example.com', $method->invoke($this->ssh, 'user@xyz.ssh.example.com'));
        $this->assertEquals('bar.ssh.example.com', $method->invoke($this->ssh, 'user@bar.ssh.example.com:/var/log/example'));
        $this->assertEquals('foo.ssh.example.com', $method->invoke($this->ssh, 'ssh://foo.ssh.example.com'));
        $this->assertEquals('ssh.example.com', $method->invoke($this->ssh, 'ssh://user@ssh.example.com:foo.git'));
        $this->assertEquals('github.com', $method->invoke($this->ssh, 'user:pass@github.com:bar.git'));
        $this->assertEquals('abc.ssh.example.com', $method->invoke($this->ssh, 'abc.ssh.example.com'));
        $this->assertFalse($method->invoke($this->ssh, 'not a URL'));
        $this->assertFalse($method->invoke($this->ssh, '###'));
    }

    public function testHostIsInternal()
    {
        $method = new \ReflectionMethod($this->ssh, 'hostIsInternal');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($this->ssh, 'user@xyz.ssh.example.com'));
        $this->assertTrue($method->invoke($this->ssh, 'user@bar.ssh.example.com:/var/log/example'));
        $this->assertTrue($method->invoke($this->ssh, 'ssh://foo.ssh.example.com'));
        $this->assertTrue($method->invoke($this->ssh, 'ssh://user@ssh.example.com'));
        $this->assertTrue($method->invoke($this->ssh, 'abc.ssh.example.com'));
        $this->assertFalse($method->invoke($this->ssh, 'abc.github.com'));
        $this->assertFalse($method->invoke($this->ssh, 'user@foo.github.com:bar.git'));
        $this->assertNull($method->invoke($this->ssh, 'not a URL'));
        $this->assertNull($method->invoke($this->ssh, '/path/to/file'));
    }
}
