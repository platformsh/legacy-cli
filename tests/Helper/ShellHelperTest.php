<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Shell;

class ShellHelperTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Test ShellHelper::execute().
     */
    public function testExecute()
    {
        $shellHelper = new Shell();

        // Find a command that will work on all platforms.
        $workingCommand = strpos(PHP_OS, 'WIN') !== false ? 'help' : 'pwd';

        // Test commandExists().
        $this->assertTrue($shellHelper->commandExists($workingCommand));
        $this->assertFalse($shellHelper->commandExists('nonexistent'));

        // With $mustRun disabled.
        $this->assertNotEmpty($shellHelper->execute([$workingCommand]));
        $this->assertFalse($shellHelper->execute(['which', 'nonexistent']));

        // With $mustRun enabled.
        $this->assertNotEmpty($shellHelper->execute([$workingCommand], null, true));
        $this->setExpectedException('Exception');
        $shellHelper->execute(['which', 'nonexistent'], null, true);
    }
}
