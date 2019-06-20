<?php

namespace Platformsh\Cli\Tests\Command\Helper;

use Platformsh\Cli\Command\Environment\EnvironmentRelationshipsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class EnvironmentRelationshipsTest extends \PHPUnit_Framework_TestCase
{
    public function setUp() {
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

    public function tearDown() {
        putenv('PLATFORM_RELATIONSHIPS=');
    }

    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new EnvironmentRelationshipsCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testGetRelationshipHost() {
        $this->assertEquals(
            'database.internal',
            rtrim($this->runCommand([
                '--property' => 'database.0.host',
            ]), "\n")
        );
    }

    public function testGetRelationshipUrl() {
        $this->assertEquals(
            'mysql://main:123@database.internal:3306/main?is_master=1',
            rtrim($this->runCommand([
                '--property' => 'database.0.url',
            ]), "\n")
        );
    }
}
