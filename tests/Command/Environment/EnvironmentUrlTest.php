<?php

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\Environment\EnvironmentUrlCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @group commands
 */
class EnvironmentUrlTest extends TestCase
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
                'to' => 'https://{default}',
                'original_url' => 'http://{default}',
            ],
        ]));
        putenv('PLATFORM_ROUTES=' . $mockRoutes);
    }

    public function tearDown(): void
    {
        putenv('PLATFORM_ROUTES=');
    }

    private function runCommand(array $args, $verbosity = OutputInterface::VERBOSITY_NORMAL) {
        $output = new BufferedOutput();
        $output->setVerbosity($verbosity);
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new EnvironmentUrlCommand())->run($input, $output);

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
        putenv('DISPLAY=fake');
        $result = $this->runCommand([
            '--browser' => 'nonexistent',
        ]);
        $this->assertStringContainsString('Command not found: nonexistent', $result);
        $this->assertStringContainsString("https://example.com\n", $result);

        $display = getenv('DISPLAY');
        putenv('DISPLAY=none');
        $result = $this->runCommand([
            '--browser' => 'nonexistent',
        ], OutputInterface::VERBOSITY_DEBUG);
        $this->assertStringContainsString('no display found', $result);
        $this->assertStringContainsString("https://example.com\n", $result);
        putenv('DISPLAY=' . $display);
    }
}
