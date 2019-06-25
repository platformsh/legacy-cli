<?php

namespace Platformsh\Cli\Tests\Command\Helper;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;

class RouteListTest extends TestCase
{
    private $mockRoutes;

    public function setUp() {
        $this->mockRoutes = base64_encode(json_encode([
            'https://example.com' => [
                'primary' => true,
                'type' => 'upstream',
                'upstream' => 'app:http',
                'original_url' => 'https://{default}',
            ],
            'http://example.com' => [
                'type' => 'redirect',
                'to' => 'https://{default}',
                'original_url' => 'http://{default}',
            ],
        ]));
    }

    private function runCommand(array $args) {
        return (new CommandRunner())
            ->run('route:list', $args, ['PLATFORM_ROUTES' => $this->mockRoutes])
            ->getOutput();
    }

    public function testListRoutes() {
        $this->assertEquals(
            "https://{default}\tupstream\tapp:http\n"
            . "http://{default}\tredirect\thttps://{default}\n",
            $this->runCommand([
                '--format', 'tsv',
                '--columns', 'route,type,to',
                '--no-header',
            ])
        );
    }
}
