<?php

namespace Platformsh\Cli\Tests\Command\Helper;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\Route\RouteGetCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RouteGetTest extends TestCase
{
    public function setUp() {
        $mockRoutes = base64_encode(json_encode([
            'https://example.com' => [
                'primary' => true,
                'type' => 'upstream',
                'upstream' => 'app:http',
                'original_url' => 'https://{default}',
            ],
            'http://example.com' => [
                'type' => 'redirect',
                'to' => 'https://example.com',
                'original_url' => 'http://{default}',
            ],
        ]));
        putenv('PLATFORM_ROUTES=' . $mockRoutes);
    }

    public function tearDown() {
        putenv('PLATFORM_ROUTES=');
    }

    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new RouteGetCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testGetPrimaryRouteUrl() {
        $this->assertEquals(
            'https://example.com',
            rtrim($this->runCommand([
                '--primary' => true,
                '--property' => 'url',
            ]), "\n")
        );
    }

    public function testGetRouteByOriginalUrl() {
        $this->assertEquals(
            'false',
            rtrim($this->runCommand([
                'route' => 'http://{default}',
                '--property' => 'primary',
            ]), "\n")
        );
        $this->assertEquals(
            'true',
            rtrim($this->runCommand([
                'route' => 'https://{default}',
                '--property' => 'primary',
            ]), "\n")
        );
    }
}
