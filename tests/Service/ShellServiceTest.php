<?php

namespace Platformsh\Cli\Tests\Service;

use Platformsh\Cli\Service\Shell;

class ShellServiceTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Test ShellHelper::execute().
     */
    public function testExecute()
    {
        $shell = new Shell();

        // Find a command that will work on all platforms.
        $workingCommand = strpos(PHP_OS, 'WIN') !== false ? 'help' : 'pwd';

        // Test commandExists().
        $this->assertTrue($shell->commandExists($workingCommand));
        $this->assertFalse($shell->commandExists('nonexistent'));

        // With $mustRun disabled.
        $this->assertNotEmpty($shell->execute([$workingCommand]));
        $this->assertFalse($shell->execute(['which', 'nonexistent']));

        // With $mustRun enabled.
        $this->assertNotEmpty($shell->execute([$workingCommand], null, true));
        $this->setExpectedException('Exception');
        $shell->execute(['which', 'nonexistent'], null, true);
    }
}
