<?php

namespace Platformsh\Cli\Tests\Command\Environment;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\Environment\EnvironmentRelationshipsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class EnvironmentRelationshipsTest extends TestCase
{
    public function setUp(): void
    {
        $mockRelationships = base64_encode(json_encode([
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
                ]
            ],
        ]));
        putenv('PLATFORM_RELATIONSHIPS=' . $mockRelationships);
    }

    public function tearDown(): void
    {
        putenv('PLATFORM_RELATIONSHIPS=');
    }

    private function runCommand(array $args): string {
        $output = new BufferedOutput();
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new EnvironmentRelationshipsCommand())
            ->run($input, $output);

        return $output->fetch();
    }

    public function testGetRelationshipHost(): void {
        $this->assertEquals(
            'database.internal',
            rtrim((string) $this->runCommand([
                '--property' => 'database.0.host',
            ]), "\n")
        );
    }

    public function testGetRelationshipUrl(): void {
        $this->assertEquals(
            'mysql://main:123@database.internal:3306/main',
            rtrim((string) $this->runCommand([
                '--property' => 'database.0.url',
            ]), "\n")
        );
    }
}
