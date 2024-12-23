<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class WelcomeCommandTest extends TestCase
{
    public function testWelcomeOnLocalContainer(): void
    {
        chdir('/');
        putenv('PLATFORM_PROJECT=test-project');
        putenv('PLATFORM_BRANCH=test-environment');
        putenv('PLATFORM_ROUTES=' . base64_encode((string) json_encode([])));
        putenv('PLATFORMSH_CLI_SESSION_ID=test' . rand(100, 999));
        $result = MockApp::runAndReturnOutput('welcome');
        $this->assertStringContainsString(
            'Project ID: test-project',
            $result,
        );
        $this->assertStringContainsString(
            'Local environment commands',
            $result,
        );
    }
}
