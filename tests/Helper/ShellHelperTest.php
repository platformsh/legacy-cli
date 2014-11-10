<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Helper\ShellHelper;

class ShellHelperTest extends \PHPUnit_Framework_TestCase
{

    /** @var ShellHelper */
    protected $shellHelper;

    public function setUp()
    {
        $this->shellHelper = new ShellHelper();
    }

    /**
     * Test ShellHelper::execute();
     */
    public function testExecute()
    {
        $this->assertNotEmpty($this->shellHelper->execute('which git'));
        $this->assertEmpty($this->shellHelper->execute('which nonexistent'));
        $this->assertEmpty($this->shellHelper->execute('nonexistent command test'));
    }

}
