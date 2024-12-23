<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class SshTest extends TestCase
{
    private ?Ssh $ssh;

    public function setUp(): void
    {
        $container = Container::instance();
        $container->set(InputInterface::class, new ArrayInput([]));
        $container->set(OutputInterface::class, new BufferedOutput());
        $container->set(Config::class, (new Config())->withOverrides([
            'ssh.domain_wildcards' => ['*.ssh.example.com'],
        ]));
        $this->ssh = $container->get(Ssh::class);
    }

    public function testGetHost(): void
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

    public function testHostIsInternal(): void
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
