<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command\Route;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class RouteGetTest extends TestCase
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

    public function testGetPrimaryRouteUrl(): void
    {
        $this->assertEquals(
            'https://example.com',
            rtrim(MockApp::runAndReturnOutput('route:get', [
                '--primary' => true,
                '--property' => 'url',
            ]), "\n"),
        );
    }

    public function testGetRouteByOriginalUrl(): void
    {
        $this->assertEquals(
            'false',
            rtrim(MockApp::runAndReturnOutput('route:get', [
                'route' => 'http://{default}',
                '--property' => 'primary',
            ]), "\n"),
        );
        $this->assertEquals(
            'true',
            rtrim(MockApp::runAndReturnOutput('route:get', [
                'route' => 'https://{default}',
                '--property' => 'primary',
            ]), "\n"),
        );
    }
}
