<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command\Route;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class RouteListTest extends TestCase
{
    public function setUp(): void
    {
        $mockRoutes = base64_encode((string) json_encode([
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

    public function testListRoutes(): void
    {
        $this->assertEquals(
            "https://{default}\tupstream\tapp:http\n"
            . "http://{default}\tredirect\thttps://{default}\n",
            MockApp::runAndReturnOutput('routes', [
                '--format' => 'tsv',
                '--columns' => ['route,type,to'],
                '--no-header' => true,
            ]),
        );
    }
}
