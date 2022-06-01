<?php

namespace Platformsh\Cli\Tests\Service;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Tests\Container;
use Symfony\Component\Console\Input\ArrayInput;

class SshDiagnosticsTest extends \PHPUnit_Framework_TestCase {
    private $sd;

    public function setUp() {
        $container = Container::instance();
        $container->set('input', new ArrayInput([]));
        $container->set('config', (new Config())->withOverrides([
            'api.ssh_domain_wildcards' => ['*.ssh.example.com'],
        ]));
        /** @var SshDiagnostics $this->sd */
        $this->sd = $container->get('ssh_diagnostics');
    }

    public function testGetHost()
    {
        $method = new \ReflectionMethod($this->sd, 'getHost');
        $method->setAccessible(true);
        $this->assertEquals('xyz.ssh.example.com', $method->invoke($this->sd, 'user@xyz.ssh.example.com'));
        $this->assertEquals('bar.ssh.example.com', $method->invoke($this->sd, 'user@bar.ssh.example.com:/var/log/example'));
        $this->assertEquals('foo.ssh.example.com', $method->invoke($this->sd, 'ssh://foo.ssh.example.com'));
        $this->assertEquals('ssh.example.com', $method->invoke($this->sd, 'ssh://user@ssh.example.com:foo.git'));
        $this->assertEquals('abc', $method->invoke($this->sd, 'abc'));
        $this->assertEquals('github.com', $method->invoke($this->sd, 'user:pass@github.com:bar.git'));
        $this->assertEquals(false, $method->invoke($this->sd, '###'));
    }

    public function testHostIsInternal()
    {
        $method = new \ReflectionMethod($this->sd, 'sshHostIsInternal');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($this->sd, 'user@xyz.ssh.example.com'));
        $this->assertTrue($method->invoke($this->sd, 'user@bar.ssh.example.com:/var/log/example'));
        $this->assertTrue($method->invoke($this->sd, 'ssh://foo.ssh.example.com'));
        $this->assertTrue($method->invoke($this->sd, 'ssh://user@ssh.example.com'));
        $this->assertTrue($method->invoke($this->sd, 'abc.ssh.example.com'));
        $this->assertFalse($method->invoke($this->sd, 'abc.github.com'));
        $this->assertFalse($method->invoke($this->sd, 'user@foo.github.com:bar.git'));
    }
}
