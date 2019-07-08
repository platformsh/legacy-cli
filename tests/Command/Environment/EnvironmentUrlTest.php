<?php

namespace Platformsh\Cli\Tests\Command\Environment;

use Platformsh\Cli\Command\Environment\EnvironmentUrlCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class EnvironmentUrlTest extends \PHPUnit_Framework_TestCase
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
                'to' => 'https://{default}',
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
        (new EnvironmentUrlCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testUrl() {
        $this->assertEquals(
            "https://example.com\n"
            . "http://example.com\n",
            $this->runCommand([
                '--pipe' => true,
            ])
        );
    }

    public function testPrimaryUrl() {
        $this->assertEquals(
            'https://example.com',
            rtrim($this->runCommand([
                '--primary' => true,
                '--browser' => '0',
            ]), "\n")
        );
    }

    public function testNonExistentBrowserIsNotFound() {
        $result = $this->runCommand([
            '--browser' => 'nonexistent',
        ]);
        $this->assertContains('Command not found: nonexistent', $result);
        $this->assertContains("https://example.com\n", $result);
    }
}
