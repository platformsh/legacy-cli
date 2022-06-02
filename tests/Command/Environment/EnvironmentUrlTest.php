<?php

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\CommandRunner;

/**
 * @group commands
 */
class EnvironmentUrlTest extends TestCase
{
    public function setUp(): void {
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

    public function tearDown(): void {
        putenv('PLATFORM_ROUTES=');
    }

    public function testUrl() {
        $this->assertEquals(
            "https://example.com\n"
            . "http://example.com\n",
            (new CommandRunner())->run('environment:url', [
                '--pipe', '-v',
            ])->getOutput()
        );
    }

    public function testPrimaryUrl() {
        $this->assertEquals(
            'https://example.com',
            rtrim((new CommandRunner())->run('environment:url', [
                '--primary',
                '--browser', '0',
                '-v'
            ])->getOutput(), "\n")
        );
    }

    public function testNonExistentBrowserIsNotFound() {
        $result = (new CommandRunner())->run('environment:url', [
            '--browser', 'nonexistent', '-v'
        ], ['DISPLAY' => 'fake']);
        $this->assertStringContainsString('Command not found: nonexistent', $result->getErrorOutput());
        $this->assertStringContainsString("https://example.com\n", $result->getOutput());

        $result = (new CommandRunner())->run('environment:url', [
            '--browser', 'nonexistent', '-v'
        ], ['DISPLAY' => 'none', 'PLATFORMSH_CLI_DEBUG' => '1']);
        $this->assertStringContainsString('no display found', $result->getErrorOutput());
        $this->assertStringContainsString("https://example.com\n", $result->getOutput());
    }
}
