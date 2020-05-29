<?php

namespace Platformsh\Cli\Tests\Command\Route;

use Platformsh\Cli\Command\Route\RouteListCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class RouteListTest extends \PHPUnit_Framework_TestCase
{
    public function setUp() {
        $mockRoutes = base64_encode(json_encode([
            'http://example.com' => [
                'type' => 'redirect',
                'to' => 'https://{default}',
                'original_url' => 'http://{default}',
            ],
            'https://example.com' => [
                'primary' => true,
                'type' => 'upstream',
                'upstream' => 'app:http',
                'original_url' => 'https://{default}',
            ],
        ]));
        putenv('PLATFORM_ROUTES=' . $mockRoutes);
    }

    public function tearDown() {
        putenv('PLATFORM_ROUTES=');
    }

    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new RouteListCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testListRoutes() {
        $this->assertEquals(
            "https://{default}\tupstream\tapp:http\n"
            . "http://{default}\tredirect\thttps://{default}\n",
            $this->runCommand([
                '--format' => 'tsv',
                '--columns' => ['route,type,to'],
                '--no-header' => true,
            ])
        );
    }
}
