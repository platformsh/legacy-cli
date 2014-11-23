<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Helper\ShellHelper;

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
        $this->assertNotEmpty($shellHelper->execute(array($workingCommand), true));
        $this->setExpectedException('Symfony\\Component\\Process\\Exception\\ProcessFailedException');
        $shellHelper->execute(array('which', 'nonexistent'), true);
    }

}
