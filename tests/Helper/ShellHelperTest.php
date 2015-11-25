<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Helper\ShellHelper;

class ShellHelperTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Test ShellHelper::execute().
     */
    public function testExecute()
    {
        $shellHelper = new ShellHelper();

        // Find a command that will work on all platforms.
        $workingCommand = strpos(PHP_OS, 'WIN') !== false ? 'help' : 'pwd';

        // With $mustRun disabled.
        $this->assertNotEmpty($shellHelper->execute(array($workingCommand)));
        $this->assertFalse($shellHelper->execute(array('which', 'nonexistent')));

        // With $mustRun enabled.
        $this->assertNotEmpty($shellHelper->execute(array($workingCommand), null, true));
        $this->setExpectedException('Exception');
        $shellHelper->execute(array('which', 'nonexistent'), null, true);
    }
}
