<?php

namespace Platformsh\Cli\Tests\Command\Route;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\Route\RouteListCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class RouteListTest extends TestCase
{
    public function setUp(): void
    {
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

    public function tearDown(): void
    {
        putenv('PLATFORM_ROUTES=');
    }

    private function runCommand(array $args): string {
        $output = new BufferedOutput();
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new RouteListCommand())->run($input, $output);

        return $output->fetch();
    }

    public function testListRoutes(): void {
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
