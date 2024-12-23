<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class EnvironmentRelationshipsTest extends TestCase
{
    public function setUp(): void
    {
        $mockRelationships = base64_encode((string) json_encode([
            'database' => [
                0 => [
                    'host' => 'database.internal',
                    'username' => 'main',
                    'password' => '123',
                    'scheme' => 'mysql',
                    'path' => 'main',
                    'port' => 3306,
                    'rel' => 'mysql',
                    'service' => 'database',
                    'query' => ['is_master' => true],
                ],
            ],
        ]));
        putenv('PLATFORM_RELATIONSHIPS=' . $mockRelationships);
    }

    public function tearDown(): void
    {
        putenv('PLATFORM_RELATIONSHIPS=');
    }

    public function testGetRelationshipHost(): void
    {
        $this->assertEquals(
            'database.internal',
            rtrim(MockApp::runAndReturnOutput('rel', [
                '--property' => 'database.0.host',
            ]), "\n"),
        );
    }

    public function testGetRelationshipUrl(): void
    {
        $this->assertEquals(
            'mysql://main:123@database.internal:3306/main',
            rtrim(MockApp::runAndReturnOutput('rel', [
                '--property' => 'database.0.url',
            ]), "\n"),
        );
    }
}
