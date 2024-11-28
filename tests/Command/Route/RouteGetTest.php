<?php

namespace Platformsh\Cli\Tests\Command\Route;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\Route\RouteGetCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class RouteGetTest extends TestCase
{
    public function setUp(): void
    {
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

    public function tearDown(): void
    {
        putenv('PLATFORM_ROUTES=');
    }

    private function runCommand(array $args) {
        $output = new BufferedOutput();
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new RouteGetCommand())->run($input, $output);

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
