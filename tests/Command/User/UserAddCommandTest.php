<?php

namespace Platformsh\Cli\Tests\Command\User;

use GuzzleHttp\Client;
use Platformsh\Cli\Command\User\UserAddCommand;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\EnvironmentType;
use Symfony\Component\Console\Output\NullOutput;

class UserAddCommandTest extends \PHPUnit_Framework_TestCase
{
    private $mockEnvironments = [];
    private $mockTypes = [];

    protected function setUp()
    {
        // Set up mock environments.
        $mockEnvironmentData = [
            ['id' => 'main', 'type' => 'production'],
            ['id' => 'stg', 'type' => 'staging'],
            ['id' => 'dev1', 'type' => 'development'],
            ['id' => 'dev2', 'type' => 'development'],
            ['id' => 'dev3', 'type' => 'development'],
        ];
        $mockTypeData = [
            ['id' => 'production'],
            ['id' => 'staging'],
            ['id' => 'development'],
        ];
        $this->mockEnvironments = [];
        $client = new Client();
        foreach ($mockEnvironmentData as $data) {
            $this->mockEnvironments[$data['id']] = new Environment($data, 'http://127.0.0.1:10000/api/projects/foo/environments', $client, true);
        }
        foreach ($mockTypeData as $data) {
            $this->mockTypes[$data['id']] = new EnvironmentType($data, 'http://127.0.0.1:10000/api/projects/foo/environment-types', $client, true);
        }
    }

    public function testGetSpecifiedEnvironmentRoles()
    {
        // Set up a mock command to make the private method accessible.
        $command = new UserAddCommand();
        $m = new \ReflectionMethod($command, 'getSpecifiedEnvironmentRoles');
        $m->setAccessible(true);

        // Test cases: triples of environment role arguments, the output, and the error message if any.
        // TODO convert to anonymous class in PHP 7
        $cases = [
            [
                ['m%:v', 'stg:admin', 'dev1%:c'],
                ['main' => 'viewer', 'stg' => 'admin', 'dev1' => 'contributor'],
            ],
            [
                ['%tg:a', 'viewer'],
                ['stg' => 'admin'],
            ],
            [
                ['main:viewer', 'nonexistent:a'],
                [],
                'No environment IDs match: nonexistent',
            ],
            [
                ['main:viewer', 'stg:invalid-role'],
                [],
                'Invalid role: invalid-role',
            ],
        ];
        foreach ($cases as $i => $case) {
            list($args, $expectedRoles) = $case;
            $errorMessage = isset($case[2]) ? $case[2] : '';
            try {
                $result = $m->invoke($command, $args, $this->mockEnvironments);
                $this->assertEquals($expectedRoles, $result, "case $i roles");
                $this->assertEquals($errorMessage, '', "case $i error message");
            } catch (\InvalidArgumentException $e) {
                $this->assertEquals($errorMessage, $e->getMessage(), "case $i error message");
            }
        }
    }

    public function testGetSpecifiedTypeRoles()
    {
        // Set up a mock command to make the private method accessible.
        $command = new UserAddCommand();
        $m = new \ReflectionMethod($command, 'getSpecifiedTypeRoles');
        $m->setAccessible(true);

        // Test cases: quintuples of role arguments, the output, whether to ignore errors, the error message if any, the remaining roles if any.
        // TODO convert to anonymous class in PHP 7
        $cases = [
            [
                ['staging:viewer', 'stg:admin', 'viewer'],
                ['staging' => 'viewer'],
                true,
                '',
                ['stg:admin', 'viewer'],
            ],
            [
                ['development:viewer', 'nonexistent:viewer', 'stg:admin'],
                [],
                false,
                'No environment type IDs match: nonexistent',
            ],
            [
                ['dev%:v', 'prod:admin'],
                ['development' => 'viewer'],
                true,
                '',
                ['prod:admin'],
            ],
        ];
        foreach ($cases as $i => $case) {
            list($roles, $expectedRoles, $ignoreErrors) = $case;
            $expectedErrorMessage = isset($case[3]) ? $case[3] : '';
            $expectedRemainingRoles = isset($case[4]) ? $case[4] : [];
            try {
                $result = $m->invokeArgs($command, [&$roles, $this->mockTypes, $ignoreErrors]);
                $this->assertEquals($expectedRoles, $result, "case $i roles");
                $this->assertEquals($expectedErrorMessage, '', "case $i error message");
                $this->assertEquals($expectedRemainingRoles, array_values($roles), "case $i remaining roles");
            } catch (\InvalidArgumentException $e) {
                $this->assertEquals($expectedErrorMessage, $e->getMessage(), "case $i error message");
            }
        }
    }

    public function testConvertEnvironmentRolesToTypeRoles()
    {
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
        foreach ($cases as $i => $case) {
            list($environmentRoles, $typeRoles, $expectedTypeRoles) = $case;
            $result = $m->invoke($command, $environmentRoles, $typeRoles, $this->mockEnvironments, $output);
            $this->assertEquals($expectedTypeRoles, $result, "case $i");
        }
    }
}
