<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class EnvironmentUrlTest extends TestCase
{
    public function setUp(): void
    {
        $mockRoutes = base64_encode((string) json_encode([
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

    public function testUrl(): void
    {
        $this->assertEquals(
            "https://example.com\n"
            . "http://example.com\n",
            MockApp::runAndReturnOutput('env:url', [
                '--pipe' => true,
            ]),
        );
    }

    public function testPrimaryUrl(): void
    {
        $this->assertEquals(
            'https://example.com',
            rtrim(MockApp::runAndReturnOutput('env:url', [
                '--primary' => true,
                '--browser' => '0',
            ]), "\n"),
        );
    }
}
