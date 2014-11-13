<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Helper\ShellHelper;

class ShellHelperTest extends \PHPUnit_Framework_TestCase
{

    /** @var ShellHelper */
    protected $shellHelper;

    /** @var string */
    protected $workingCommand;

    public function setUp()
    {
        $this->shellHelper = new ShellHelper();
        $this->workingCommand = strpos(PHP_OS, 'WIN') !== false ? 'help' : 'pwd';
    }

    /**
     * Test ShellHelper::execute();
     */
    public function testExecute()
    {
        $this->assertNotEmpty($this->shellHelper->execute($this->workingCommand));
        $this->assertEmpty($this->shellHelper->execute('which nonexistent'));
        $this->assertEmpty($this->shellHelper->execute('nonexistent command test'));
    }

    /**
     * Test ShellHelper::executeArgs().
     */
    public function testExecuteArgs()
    {
        $this->assertNotEmpty($this->shellHelper->executeArgs(array($this->workingCommand)), false);
        $this->assertEmpty($this->shellHelper->executeArgs(array('which', 'nonexistent'), false));

        $this->assertNotEmpty($this->shellHelper->executeArgs(array($this->workingCommand)), true);
        $this->setExpectedException('Symfony\\Component\\Process\\Exception\\ProcessFailedException');
        $this->shellHelper->executeArgs(array('which', 'nonexistent'), true);
    }

}
