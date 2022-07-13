<?php

namespace Platformsh\Cli\Tests\Command\User;

use GuzzleHttp\Client;
use Platformsh\Cli\Command\User\UserAddCommand;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Output\NullOutput;

class UserAddCommandTest extends \PHPUnit_Framework_TestCase
{
    public function testConvertEnvironmentRolesToTypeRoles()
    {
        // Set up mock environments.
        $mockEnvironmentData = [
            ['id' => 'main', 'type' => 'production'],
            ['id' => 'stg', 'type' => 'staging'],
            ['id' => 'dev1', 'type' => 'development'],
            ['id' => 'dev2', 'type' => 'development'],
            ['id' => 'dev3', 'type' => 'development'],
        ];
        $mockEnvironments = [];
        $client = new Client();
        foreach ($mockEnvironmentData as $data) {
            $mockEnvironments[$data['id']] = new Environment($data, 'http://127.0.0.1:10000/api/projects/foo/environments', $client, true);
        }

        // Set up a mock command to make the private method accessible.
        $command = new UserAddCommand();
        $m = new \ReflectionMethod($command, 'convertEnvironmentRolesToTypeRoles');
        $m->setAccessible(true);

        // Test cases: triples of environment roles, type roles, and output (converted type roles, or false on error).
        $cases = [
            [
                ['stg' => 'viewer', 'dev1' => 'contributor'],
                [],
                ['staging' => 'viewer', 'development' => 'contributor'],
            ],
            [
                ['main' => 'viewer', 'stg' => 'admin', 'dev2' => 'admin'],
                [],
                ['production' => 'viewer', 'staging' => 'admin', 'development' => 'admin'],
            ],
            [
                ['stg' => 'viewer', 'dev1' => 'contributor', 'dev2' => 'viewer'],
                [],
                false,
            ],
            [
                ['main' => 'viewer', 'stg' => 'admin', 'dev2' => 'admin'],
                ['development' => 'admin'],
                ['production' => 'viewer', 'staging' => 'admin', 'development' => 'admin'],
            ],
            [
                ['main' => 'viewer', 'stg' => 'admin', 'dev2' => 'admin'],
                ['development' => 'contributor'],
                false,
            ],
        ];
        $output = new NullOutput();
        foreach ($cases as $case) {
            list($environmentRoles, $typeRoles, $expectedTypeRoles) = $case;
            $result = $m->invoke($command, $environmentRoles, $typeRoles, $mockEnvironments, $output);
            $this->assertEquals($expectedTypeRoles, $result);
        }
    }
}
