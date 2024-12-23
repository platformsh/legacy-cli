<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Service\Shell;

class ShellServiceTest extends TestCase
{
    /**
     * Test Shell::execute().
     */
    public function testExecute(): void
    {
        $shell = new Shell();

        // Find a command that will work on all platforms.
        $workingCommand = str_contains(PHP_OS, 'WIN') ? 'help' : 'pwd';

        // Test commandExists().
        $this->assertTrue($shell->commandExists($workingCommand));
        $this->assertFalse($shell->commandExists('nonexistent'));

        // With $mustRun disabled.
        $this->assertNotEmpty($shell->execute([$workingCommand]));
        $this->assertFalse($shell->execute(['which', 'nonexistent']));

        // With $mustRun enabled.
        $this->assertNotEmpty($shell->execute([$workingCommand], mustRun: true));
        $this->expectException(\Exception::class);
        $shell->execute(['which', 'nonexistent'], mustRun: true);
    }

    /**
     * Test Shell::mustExecute().
     */
    public function testMustExecute(): void
    {
        $shell = new Shell();

        $workingCommand = str_contains(PHP_OS, 'WIN') ? 'help' : 'pwd';

        $this->assertNotEmpty($shell->mustExecute($workingCommand));
        $this->expectException(ProcessFailedException::class);
        $shell->mustExecute(['which', 'nonexistent']);
    }
}
